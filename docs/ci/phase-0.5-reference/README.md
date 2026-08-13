# Phase 0.5 CI validation reference

This directory permanently preserves the completed Phase 0.5 runtime-validation implementation as reference material. Phase 0.5 was implemented and validated with the locally available static checks, workflow YAML parsing, and shell syntax checks.

The workflow could not be pushed as an active GitHub Actions workflow because the Arena GitHub App does not have GitHub's `workflows` permission. Therefore, `p0-security.yml` in this directory is a reference artifact only: GitHub does not execute workflow files outside `.github/workflows/`.

When the repository connection has permission to create or update workflow files, the exact `p0-security.yml` artifact can be restored to `.github/workflows/p0-security.yml`, reviewed, committed, and executed. Until an actual GitHub Actions run completes successfully, PHP/MariaDB runtime validation is **not** claimed as passed.

`MANIFEST.txt` and `phase-0.5.patch` are preserved verbatim from the local recovery artifact. The manifest's original `storage/phase-0.5-reference/` paths describe where the files were held before this tracked copy was created. The expected SHA-256 for `p0-security.yml` is:

```text
2488f566d7828813fd046fc0ddcd835ef5beb2704dfa62b3b5e319d63408ec7b
```

The workflow uses only explicitly disposable test-database values. It contains no production credentials or repository secrets.
