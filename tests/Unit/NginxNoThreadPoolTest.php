<?php

use PHPUnit\Framework\TestCase;

/**
 * No nginx thread pool. The shipped nginx is statically linked: at a
 * worker's graceful exit, exit() runs the global destructors, which
 * deregister the binary's unwind tables (__deregister_frame_info_bases),
 * while the pool's threads are still unwinding out of pthread_exit()
 * (ngx_thread_pool_destroy() waits for each thread's flag, set just before
 * pthread_exit(), not for the thread). A thread that finds no frame info
 * aborts: signal 6 in "worker process is shutting down", about one reload
 * in eleven on the test MAIN (a core shows the two stacks). The pool did no
 * work anyway: max_queue=0 refused every task.
 */
final class NginxNoThreadPoolTest extends TestCase {
	public function testNoThreadPoolOrThreadedAio(): void {
		$rRoot = dirname(__DIR__, 2);
		foreach (['src/bin/nginx/conf/nginx.conf', 'lb_configs/nginx.conf'] as $rFile) {
			$rConf = (string) file_get_contents($rRoot . '/' . $rFile);
			$this->assertDoesNotMatchRegularExpression('/^\s*thread_pool\b/m', $rConf, $rFile);
			$this->assertDoesNotMatchRegularExpression('/^\s*aio\s+threads\b/m', $rConf, $rFile);
			$this->assertMatchesRegularExpression('/^\s*sendfile on;$/m', $rConf, $rFile . ': segments still go out by sendfile');
		}
	}
}
