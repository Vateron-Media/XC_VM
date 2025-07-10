<?php

class Chain {
	private $name;
	private $table;
	private $policy;
	private $rules = array();

	public function __construct($name, $table, $policy) {
		$this->name = $name;
		$this->policy = $policy;
		$this->table = $table;
	}

	public function insertRule(Rule $rule, $id) {
		$tmp = array_slice($this->rules, 0, $id - 1) + array('tmp' => $rule) + array_slice($this->rules, $id - 2);
		$this->rules = $tmp;
		$this->resetIndexes();

		return (new Command($this->table))->setOptions((string) $rule)->insertRule($this->getName(), $id);
	}

	public function appendRule(Rule $rule) {
		$nextId = count($this->rules) + 1;
		$this->rules[$nextId] = $rule;
		$rule->setNum($nextId);

		return (new Command($this->table))->setOptions((string) $rule)->appendRule($this->getName());
	}

	public function replaceRule(Rule $rule, $id) {
		$this->rules[$id] = $rule;
		$rule->setNum($id);

		return (new Command($this->table))->setOptions((string) $rule)->replaceRule($this->getName(), $id);
	}

	public function deleteRule($id) {
		unset($this->rules[$id]);
		$this->resetIndexes();

		return (new Command($this->table))->deleteRule($this->getName(), $id);
	}

	public function flush() {
		$this->rules = array();

		return (new Command($this->table))->flushChain($this->getName());
	}

	public function rename($newName) {
		$oldName = $this->name;
		$this->name = $newName;

		return (new Command($this->table))->renameChain($oldName, $newName);
	}

	private function resetIndexes() {
		$this->rules = array_combine(array_keys(array_fill(1, count($this->rules), null)), $this->rules);

		foreach ($this->rules as $index => $rule) {
			$rule->setNum($index);
		}
	}

	public function getRulesList() {
		return $this->rules;
	}

	public function getName() {
		return $this->name;
	}

	public function getPolicy() {
		return $this->policy;
	}
}

class Rule {
	public $num;
	public $target;
	public $protocol;
	public $source;
	public $destination;
	public $options = array();

	public function __construct($target, $protocol, $source = null, $destination = null, $options = array()) {
		$this->target = $target;
		$this->protocol = $protocol;
		$this->source = $source;
		$this->destination = $destination;

		if (is_array($options)) {
			$this->options = $options;
		} else {
			$this->parseOptions($options);
		}
	}

	public function getNum() {
		return $this->num;
	}

	public function setNum($num) {
		$this->num = $num;
	}

	private function parseOptions($options) {
		if (preg_match('/(?:(?<direction>(s|d)pt)s?:)(?<exclude>!?)(?<ports>(\\d+):?(\\d+))/', $options, $parsed)) {
			$map = array('dpt' => '--destination-port', 'spt' => '--source-port');
			$excl = (!empty($parsed['exclude']) ? $parsed['exclude'] . ' ' : '');
			$this->options[$map[$parsed['direction']]] = $excl . $parsed['ports'];
		}

		if (preg_match_all('/MAC\\s(?<macaddress>[\\d:]+)/', $options, $parsed)) {
			for ($i = 0; $i < count($parsed['macaddress']); $i++) {
				$this->options['--match'][] = 'mac --mac-source ' . $parsed['macaddress'][$i];
			}
		}

		if (preg_match_all('/limit:\\savg\\s(?<limit>\\d+\\/(sec|min|hour|day))\\sburst\\s(?<burst>\\d+)/', $options, $parsed)) {
			for ($i = 0; $i < count($parsed['limit']); $i++) {
				$this->options['--match'][] = 'limit --limit ' . $parsed['limit'][$i] . ' --limit-burst ' . $parsed['burst'][$i];
			}
		}
	}

	public function __toString() {
		$cmd = '';

		if ('all' != $this->protocol) {
			$cmd .= ' --proto ' . $this->protocol;
		}

		if (!is_null($this->source) && ('0.0.0.0/0' != $this->source)) {
			$cmd .= ' --source ' . $this->source;
		}

		if (!is_null($this->destination) && ('0.0.0.0/0' != $this->destination)) {
			$cmd .= ' --destination ' . $this->destination;
		}

		foreach ($this->options as $k => $value) {
			if (is_scalar($value)) {
				$cmd .= ' ' . $k . ' ' . $value;
			} else {
				foreach ($value as $option) {
					$cmd .= ' ' . $k . ' ' . $option;
				}
			}
		}

		$cmd .= (!empty($this->target) ? ' --jump ' . $this->target : '');

		return $cmd;
	}
}

class IptablesService {
	private $tables;

	public function __construct() {
	}

	public function parseIptablesChains(Table $table) {
		$data = explode("\n", $table->getRaw());
		$patterns = array('chain' => '/(?:Chain\\s)' . "\r\n" . '                        (?<chain>[^\\s]+)' . "\r\n" . '                        (?:.*\\()' . "\r\n" . '                        (?<policy>.*)' . "\r\n" . '                        (?:\\).*)/x', 'rule' => '/(?<id>\\d+)\\s+' . "\r\n" . '                        (?<target>\\w+)\\s+' . "\r\n" . '                        (?<protocol>\\w+)\\s+' . "\r\n" . '                        (?<opt>[\\w-]+)\\s+' . "\r\n" . '                        (?<source>[0-9\\.\\/]+)\\s+' . "\r\n" . '                        (?<destination>[0-9\\.\\/]+)\\s+' . "\r\n" . '                        ?(?<options>.*)/x');

		foreach ($data as $row) {
			if (preg_match($patterns['chain'], $row, $out)) {
				$chain = new Chain($out['chain'], $table->getName(), $out['policy']);
				$table->addChain($chain);
				$this->tables[$table->getName()] = $table;
			}

			if (isset($chain) && preg_match($patterns['rule'], $row, $out)) {
				$rule = new Rule($out['target'], $out['protocol'], $out['source'], $out['destination'], trim($out['options']));
				$rule->setNum($out['id']);
				$chain->insertRule($rule, $out['id']);
			}
		}

		return $table->getChainsList();
	}
}

class Command {
	private $options = array();
	private $rawOptions;
	private $tcpFlags = array('ACK', 'FIN', 'PSH', 'RST', 'SYN', 'URG', 'ALL', 'NONE');
	private $matchModules = array('limit', 'mac', 'state', 'mark', 'recent');
	private $cmd;
	private $table;

	public function __construct($table) {
		$this->table = $table;
	}

	public function appendRule($chainName) {
		$this->cmd = '-A ' . $chainName;

		return (string) $this;
	}

	public function deleteRule($chainName, $ruleNum) {
		$this->cmd = '-D ' . $chainName . ' ' . $ruleNum;

		return (string) $this;
	}

	public function replaceRule($chainName, $ruleNum) {
		$this->cmd = '-R ' . $chainName . ' ' . $ruleNum;

		return (string) $this;
	}

	public function insertRule($chainName, $ruleNum = 1) {
		$this->cmd = '-I ' . $chainName . ' ' . $ruleNum;

		return (string) $this;
	}

	public function flushChain($chainName) {
		$this->cmd = '-F ' . $chainName;
		$this->cleanOptions();

		return (string) $this;
	}

	public function createChain($chainName) {
		$this->cmd = '-N ' . $chainName;

		return (string) $this;
	}

	public function deleteChain($chainName) {
		$this->cmd = '-X ' . $chainName;
		$this->cleanOptions();

		return (string) $this;
	}

	public function renameChain($chainName, $newName) {
		$this->cmd = '-E ' . $chainName . ' ' . $newName;
		$this->cleanOptions();

		return (string) $this;
	}

	private function cleanOptions() {
		$this->options = array();
		$this->rawOptions = null;
	}

	public function setProtocol($protocol, $sourcePort = null, $destinationPort = null, $tcpFlags = null) {
		$this->options['--proto'] = $protocol;

		if (in_array($protocol, array('tcp', 'udp')) && !is_null($sourcePort)) {
			$this->options['--proto'] .= ' --source-port ' . $sourcePort;
		}

		if (in_array($protocol, array('tcp', 'udp')) && !is_null($destinationPort)) {
			$this->options['--proto'] .= ' --destination-port ' . $destinationPort;
		}

		if (('tcp' == $protocol) && is_array($tcpFlags)) {
			$diff = array_diff($tcpFlags, $this->tcpFlags);

			if (!empty($diff)) {
				throw new Exception('Unexpected tcp-flags: ' . implode($diff));
			}

			$this->options['--proto'] .= ' --tcp-flags ' . implode(',', $tcpFlags);
		}

		return $this;
	}

	public function setSource($value) {
		$this->options['--source'] = $value;

		return $this;
	}

	public function setDestination($value) {
		$this->options['--destination'] = $value;

		return $this;
	}

	public function setJump($jump, ?array $params = null) {
		$this->options['--jump'] = $jump;

		if (!empty($params)) {
			foreach ($params as $name => $value) {
				$this->options['--jump'] .= ' --' . $name . ' ' . $value;
			}
		}

		return $this;
	}

	public function setMatch($module, array $params) {
		if (!in_array($module, $this->matchModules)) {
			throw new Exception('Invalid module: ' . $module);
		}

		$match = $module;

		foreach ($params as $name => $value) {
			$key = '--' . $module . ($name != $module ? '-' . $name : '');
			$match .= ' ' . $key . ' ' . $value;
		}

		$this->options['--match'] = $match;

		return $this;
	}

	public function setInput($value) {
		$this->options['--in-interface'] = $value;

		return $this;
	}

	public function setOutput($value) {
		$this->options['--out-interface'] = $value;

		return $this;
	}

	public function setOptions($options) {
		$this->rawOptions = $options;

		return $this;
	}

	public function __toString() {
		$cmd = '';

		if (!is_null($this->cmd)) {
			$cmd .= 'iptables ';
			$cmd .= $this->cmd;
			$cmd .= ' -t ' . $this->table;
		}

		if (!is_null($this->rawOptions)) {
			return $cmd . ' ' . $this->rawOptions;
		}

		foreach ($this->options as $k => $value) {
			if (is_scalar($value)) {
				$cmd .= ' ' . $k . ' ' . $value;
			} else {
				foreach ($value as $option) {
					$cmd .= ' ' . $k . ' ' . $option;
				}
			}
		}

		return $cmd;
	}
}

class Table {
	public const TABLE_RAW = 'raw';
	public const TABLE_FILTER = 'filter';
	public const TABLE_NAT = 'nat';
	public const TABLE_MANGLE = 'mangle';
	public const TABLE_SECURITY = 'security';

	protected $name;
	protected $raw;
	protected $chains = array();

	public function getRaw() {
		return $this->raw;
	}

	public function setRaw($raw) {
		$this->raw = $raw;
	}

	public function addChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
	}

	public function createChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
		$rulesCounter = count($chain->getRulesList());
		$command = new Command($this->name);

		if (1 < $rulesCounter) {
			throw new Exception('Maximum number Rules on creation is 1.');
		} elseif (1 === $rulesCounter) {
			$command->setOptions((string) current($chain->getRulesList()));
		}

		return $command->createChain($chain->getName());
	}

	public function deleteChain($name) {
		$chain = $this->getChainByName($name);
		unset($this->chains[$name]);

		return (new Command($this->name))->deleteChain($chain->getName());
	}

	public function getChainsList() {
		return $this->chains;
	}

	public function getChainByName($name) {
		foreach ($this->chains as $chain) {
			if ($name === $chain->getName()) {
				return $chain;
			}
		}

		throw new Exception('Chain not found.');
	}

	public function getName() {
		return $this->name;
	}
}

class TableFactory {
	public function build($name) {
		switch ($name) {
			case 'filter':
				return new FilterTable();

			case 'mangle':
				return new MangleTable();

			case 'nat':
				return new NatTable();

			case 'raw':
				return new RawTable();

			case 'security':
				return new SecurityTable();
		}

		throw new Exception('Not found!');
	}
}

class FilterTable extends Table {
	public const TABLE_RAW = 'raw';
	public const TABLE_FILTER = 'filter';
	public const TABLE_NAT = 'nat';
	public const TABLE_MANGLE = 'mangle';
	public const TABLE_SECURITY = 'security';

	protected $name = 'filter';
	protected $raw;
	protected $chains = array();

	public function getRaw() {
		return $this->raw;
	}

	public function setRaw($raw) {
		$this->raw = $raw;
	}

	public function addChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
	}

	public function createChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
		$rulesCounter = count($chain->getRulesList());
		$command = new Command($this->name);

		if (1 < $rulesCounter) {
			throw new Exception('Maximum number Rules on creation is 1.');
		} elseif (1 === $rulesCounter) {
			$command->setOptions((string) current($chain->getRulesList()));
		}

		return $command->createChain($chain->getName());
	}

	public function deleteChain($name) {
		$chain = $this->getChainByName($name);
		unset($this->chains[$name]);

		return (new Command($this->name))->deleteChain($chain->getName());
	}

	public function getChainsList() {
		return $this->chains;
	}

	public function getChainByName($name) {
		foreach ($this->chains as $chain) {
			if ($name === $chain->getName()) {
				return $chain;
			}
		}

		throw new Exception('Chain not found.');
	}

	public function getName() {
		return $this->name;
	}
}

class MangleTable extends Table {
	public const TABLE_RAW = 'raw';
	public const TABLE_FILTER = 'filter';
	public const TABLE_NAT = 'nat';
	public const TABLE_MANGLE = 'mangle';
	public const TABLE_SECURITY = 'security';

	protected $name = 'mangle';
	protected $raw;
	protected $chains = array();

	public function getRaw() {
		return $this->raw;
	}

	public function setRaw($raw) {
		$this->raw = $raw;
	}

	public function addChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
	}

	public function createChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
		$rulesCounter = count($chain->getRulesList());
		$command = new Command($this->name);

		if (1 < $rulesCounter) {
			throw new Exception('Maximum number Rules on creation is 1.');
		} elseif (1 === $rulesCounter) {
			$command->setOptions((string) current($chain->getRulesList()));
		}

		return $command->createChain($chain->getName());
	}

	public function deleteChain($name) {
		$chain = $this->getChainByName($name);
		unset($this->chains[$name]);

		return (new Command($this->name))->deleteChain($chain->getName());
	}

	public function getChainsList() {
		return $this->chains;
	}

	public function getChainByName($name) {
		foreach ($this->chains as $chain) {
			if ($name === $chain->getName()) {
				return $chain;
			}
		}

		throw new Exception('Chain not found.');
	}

	public function getName() {
		return $this->name;
	}
}

class NatTable extends Table {
	public const TABLE_RAW = 'raw';
	public const TABLE_FILTER = 'filter';
	public const TABLE_NAT = 'nat';
	public const TABLE_MANGLE = 'mangle';
	public const TABLE_SECURITY = 'security';

	protected $name = 'nat';
	protected $raw;
	protected $chains = array();

	public function getRaw() {
		return $this->raw;
	}

	public function setRaw($raw) {
		$this->raw = $raw;
	}

	public function addChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
	}

	public function createChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
		$rulesCounter = count($chain->getRulesList());
		$command = new Command($this->name);

		if (1 < $rulesCounter) {
			throw new Exception('Maximum number Rules on creation is 1.');
		} elseif (1 === $rulesCounter) {
			$command->setOptions((string) current($chain->getRulesList()));
		}

		return $command->createChain($chain->getName());
	}

	public function deleteChain($name) {
		$chain = $this->getChainByName($name);
		unset($this->chains[$name]);

		return (new Command($this->name))->deleteChain($chain->getName());
	}

	public function getChainsList() {
		return $this->chains;
	}

	public function getChainByName($name) {
		foreach ($this->chains as $chain) {
			if ($name === $chain->getName()) {
				return $chain;
			}
		}

		throw new Exception('Chain not found.');
	}

	public function getName() {
		return $this->name;
	}
}

class RawTable extends Table {
	public const TABLE_RAW = 'raw';
	public const TABLE_FILTER = 'filter';
	public const TABLE_NAT = 'nat';
	public const TABLE_MANGLE = 'mangle';
	public const TABLE_SECURITY = 'security';

	protected $name = 'raw';
	protected $raw;
	protected $chains = array();

	public function getRaw() {
		return $this->raw;
	}

	public function setRaw($raw) {
		$this->raw = $raw;
	}

	public function addChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
	}

	public function createChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
		$rulesCounter = count($chain->getRulesList());
		$command = new Command($this->name);

		if (1 < $rulesCounter) {
			throw new Exception('Maximum number Rules on creation is 1.');
		} elseif (1 === $rulesCounter) {
			$command->setOptions((string) current($chain->getRulesList()));
		}

		return $command->createChain($chain->getName());
	}

	public function deleteChain($name) {
		$chain = $this->getChainByName($name);
		unset($this->chains[$name]);

		return (new Command($this->name))->deleteChain($chain->getName());
	}

	public function getChainsList() {
		return $this->chains;
	}

	public function getChainByName($name) {
		foreach ($this->chains as $chain) {
			if ($name === $chain->getName()) {
				return $chain;
			}
		}

		throw new Exception('Chain not found.');
	}

	public function getName() {
		return $this->name;
	}
}

class SecurityTable extends Table {
	public const TABLE_RAW = 'raw';
	public const TABLE_FILTER = 'filter';
	public const TABLE_NAT = 'nat';
	public const TABLE_MANGLE = 'mangle';
	public const TABLE_SECURITY = 'security';

	protected $name = 'security';
	protected $raw;
	protected $chains = array();

	public function getRaw() {
		return $this->raw;
	}

	public function setRaw($raw) {
		$this->raw = $raw;
	}

	public function addChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
	}

	public function createChain(Chain $chain) {
		$this->chains[$chain->getName()] = $chain;
		$rulesCounter = count($chain->getRulesList());
		$command = new Command($this->name);

		if (1 < $rulesCounter) {
			throw new Exception('Maximum number Rules on creation is 1.');
		} elseif (1 === $rulesCounter) {
			$command->setOptions((string) current($chain->getRulesList()));
		}

		return $command->createChain($chain->getName());
	}

	public function deleteChain($name) {
		$chain = $this->getChainByName($name);
		unset($this->chains[$name]);

		return (new Command($this->name))->deleteChain($chain->getName());
	}

	public function getChainsList() {
		return $this->chains;
	}

	public function getChainByName($name) {
		foreach ($this->chains as $chain) {
			if ($name === $chain->getName()) {
				return $chain;
			}
		}

		throw new Exception('Chain not found.');
	}

	public function getName() {
		return $this->name;
	}
}
