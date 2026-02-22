# Code Review — Extensions and Templates

This companion document provides concrete templates and rules to extend the base `.github/instructions/code-review.md` process.

## Example finding template (required)
- **Title:** Short descriptive title
- **Files (path:line):** repo/path/file.php#L123
- **Severity:** Critical / High / Medium / Low
- **Confidence:** High / Medium / Low
- **Description:** One-paragraph clear description of the issue
- **Reproduction / PoC:** Steps or minimal input to reproduce (if applicable)
- **Impact:** What happens and why it matters
- **Suggested mitigation / patch:** Short actionable mitigation or code snippet
- **Suggested owner:** team or role responsible

Provide at least one concrete `suggested_patch` snippet (diff or commands) for every High or Critical severity finding.

## Severity matrix (guidance)
- **Critical:** Remote, unauthenticated exploit leading to full system compromise or license bypass; trivial to reproduce. (Use sparingly)
- **High:** Authenticated or easily reachable vulnerability allowing data exfiltration, RCE, privilege escalation, or financial/license bypass.
- **Medium:** Localized data exposure, logic flaws with constrained impact, or costly remediation but no immediate compromise.
- **Low:** Maintainability, style, minor misconfigurations, or unlikely edge-cases.

When assigning severity, include brief justification mapping to the matrix.

## Deduplication / ownership rules
- Agents must not repeat identical findings. If an agent finds the same issue another agent reported, it must cite the canonical finding using the "Files (path:line)" and add complementary context (e.g., exploit scenario, perf numbers, or architectural rationale).
- The first agent that reports a finding should be considered the canonical source; downstream agents must reference it like: "See finding: repo/path/file.php#L123 (Architect)" and then add only non-duplicate information.
- If two agents produce conflicting severities, the conflict must be recorded in the Final Verdict with both opinions and a recommended reconciled severity.

## Metadata to include in every finding (machine-parsable)
- title: string
- file: string (path#L)
- severity: Critical|High|Medium|Low
- confidence: High|Medium|Low
- owner: string
- suggested_patch: (optional) small diff or commands

Recommended format: include the above as a JSON code block at the end of each finding to aid automation. Example:

```
{
  "title": "SQL injection in getUser()",
  "file": "src/auth/User.php#L210",
  "severity": "High",
  "confidence": "High",
  "owner": "backend-team",
  "suggested_patch": "patch.diff"
}
```

## Tools and artifacts (optional)
- Agents are encouraged to attach or reference automated artifacts when relevant: SAST output, linter snippets, benchmark graphs, flamegraphs, heap dumps, or small testcases.
- If external scanning tools are used, note the tool name and version and attach the raw output or a short excerpt.

## Examples and expectations
- Include one worked example (one High severity finding) in reviews produced by the system during onboarding runs to demonstrate the required level of detail and patch quality.

---

If you prefer, I can re-run and attempt to patch the original `code-review.md` to integrate these extensions in-place; currently I created this companion file to avoid modifying the original file directly.