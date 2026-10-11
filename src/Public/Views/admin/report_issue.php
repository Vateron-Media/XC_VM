<?php

/**
 * Report an Issue (Bootstrap 5): a short form that opens the matching GitHub
 * issue form in a new tab, signed in as the administrator's own GitHub account,
 * with the version and environment prefilled. The panel sends nothing itself;
 * the link is built in the browser and the administrator submits on GitHub.
 *
 * In scope: $rRepo (owner/name), $rEnvironment (version, build_type, environment).
 */

use XcVm\Core\Util\LayoutRenderer;

$rSecurityUrl = 'https://github.com/' . $rRepo . '/security/advisories/new';

?>

<div class="card mb-6">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('report_issue'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('report_issue_intro'); ?></p>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-4">
                <label class="form-label" for="issue-category"><?= $language::get('report_issue_category'); ?></label>
                <select class="form-select" id="issue-category">
                    <option value="bug"><?= $language::get('report_issue_cat_bug'); ?></option>
                    <option value="feature"><?= $language::get('report_issue_cat_feature'); ?></option>
                    <option value="docs"><?= $language::get('report_issue_cat_docs'); ?></option>
                    <option value="other"><?= $language::get('report_issue_cat_other'); ?></option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="issue-title"><?= $language::get('report_issue_title'); ?></label>
                <input type="text" class="form-control" id="issue-title" maxlength="200" placeholder="<?= $language::get('report_issue_title_placeholder'); ?>">
            </div>
            <div class="col-12">
                <label class="form-label" for="issue-description"><?= $language::get('report_issue_description'); ?></label>
                <textarea class="form-control" id="issue-description" rows="5" placeholder="<?= $language::get('report_issue_description_placeholder'); ?>"></textarea>
            </div>
            <div class="col-12" id="issue-env-wrap">
                <label class="form-label mb-1"><?= $language::get('report_issue_environment'); ?></label>
                <pre class="bg-body-tertiary border rounded p-3 mb-0 small" id="issue-env"></pre>
            </div>
            <?php if ($rCanAttachLog && $rLog !== ''): ?>
            <div class="col-12" id="issue-log-wrap">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label mb-0"><?= $language::get('report_issue_log_label'); ?></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="issue-log-copy"><?= $language::get('report_issue_log_copy'); ?></button>
                </div>
                <pre class="bg-body-tertiary border rounded p-3 mb-1 small" id="issue-log" style="max-height: 16rem; overflow: auto;"></pre>
                <div class="form-text"><?= $language::get('report_issue_log_note'); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-footer d-flex align-items-center gap-3">
        <button type="button" class="btn btn-primary" id="issue-open">
            <i class="ti tabler-brand-github me-1"></i><?= $language::get('report_issue_open'); ?>
        </button>
        <span class="text-body-secondary small"><?= $language::get('report_issue_opens_github'); ?></span>
    </div>
</div>

<div class="alert alert-warning" role="alert">
    <i class="ti tabler-shield-lock me-1"></i>
    <?= $language::get('report_issue_security_note'); ?>
    <a href="<?= htmlspecialchars($rSecurityUrl, ENT_QUOTES); ?>" target="_blank" rel="noopener noreferrer"><?= $language::get('report_issue_security_link'); ?></a>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var repo = <?= json_encode($rRepo); ?>;
        var env = <?= json_encode($rEnvironment); ?>;
        var log = <?= json_encode($rLog); ?>;

        // Per category: the issue form file, the title prefix the form uses,
        // the id of its description field, and which env fields the form asks
        // for (so a value is prefilled only where the form has that field). The
        // error log is never put in the URL — the admin copies and pastes it.
        var CATEGORIES = {
            bug: { template: 'bug_report.yml', prefix: '[BUG]: ', descField: 'description', fields: ['version', 'build_type', 'environment'] },
            feature: { template: 'feature_request.yml', prefix: '[FEATURE]: ', descField: 'description', fields: [] },
            docs: { template: 'documentation.yml', prefix: '', descField: 'description', fields: [] },
            other: { template: 'other.yml', prefix: '[OTHER]: ', descField: 'issuedescription', fields: ['version'] }
        };

        var catSel = document.getElementById('issue-category');
        var titleIn = document.getElementById('issue-title');
        var descIn = document.getElementById('issue-description');
        var envPre = document.getElementById('issue-env');
        var envWrap = document.getElementById('issue-env-wrap');
        var openBtn = document.getElementById('issue-open');
        var logPre = document.getElementById('issue-log');
        var logCopy = document.getElementById('issue-log-copy');
        if (logPre) {
            logPre.textContent = log;
        }

        // The environment block only goes on the forms that have the field (Bug).
        function refreshEnv() {
            var cat = CATEGORIES[catSel.value] || CATEGORIES.bug;
            if (cat.fields.indexOf('environment') !== -1) {
                envPre.textContent = env.environment;
                envWrap.style.display = '';
            } else if (cat.fields.indexOf('version') !== -1) {
                envPre.textContent = 'XC_VM: ' + env.version;
                envWrap.style.display = '';
            } else {
                envWrap.style.display = 'none';
            }
        }

        function buildUrl() {
            var cat = CATEGORIES[catSel.value] || CATEGORIES.bug;
            var params = new URLSearchParams();
            params.set('template', cat.template);

            var title = titleIn.value.trim();
            // title= sets the issue title; sending it replaces the form's own
            // prefix, so keep the prefix when the admin typed a title. With no
            // title, omit it and the form's default prefix stands.
            if (title) {
                params.set('title', cat.prefix + title);
            }
            var desc = descIn.value.trim();
            if (desc) {
                params.set(cat.descField, desc);
            }
            if (cat.fields.indexOf('version') !== -1 && env.version) {
                params.set('version', env.version);
            }
            if (cat.fields.indexOf('build_type') !== -1) {
                params.set('build_type', env.build_type);
            }
            if (cat.fields.indexOf('environment') !== -1) {
                params.set('environment', env.environment);
            }
            // The error log is never added here: the admin copies it and pastes it
            // into the issue, so it is reviewed in GitHub's editor before it is sent.
            return 'https://github.com/' + repo + '/issues/new?' + params.toString();
        }

        // Copy the shown log to the clipboard; where the clipboard API is
        // unavailable (plain http), select it so the admin can copy by hand.
        if (logCopy && logPre) {
            logCopy.addEventListener('click', function() {
                var done = function() { logCopy.textContent = '✓'; setTimeout(function() { logCopy.textContent = <?= json_encode($language::get('report_issue_log_copy')); ?>; }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(logPre.textContent).then(done, function() { selectNode(logPre); });
                } else {
                    selectNode(logPre);
                }
            });
        }

        function selectNode(node) {
            var range = document.createRange();
            range.selectNodeContents(node);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }

        catSel.addEventListener('change', refreshEnv);
        openBtn.addEventListener('click', function() {
            window.open(buildUrl(), '_blank', 'noopener,noreferrer');
        });
        refreshEnv();
    })();
</script>
