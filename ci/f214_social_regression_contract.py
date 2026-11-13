#!/usr/bin/env python3
"""F1-214: guard permanent social CI regressions, using only local files."""
from pathlib import Path

ROOT=Path(__file__).resolve().parent.parent
WORKFLOW=ROOT/".github/workflows/synthetic-php.yml"
REQUIRED=[
    "f209-composite-social-private-package.php",
    "f210-social-card-inspection.php",
    "f211-private-social-card-readback.php",
    "f213-visual-review-export-parity.php",
]
SELF="python3 ci/f214_social_regression_contract.py"

def verify(source: str)->None:
    if source.count("\n  pull_request:\n")!=1 or (
        "\non:\n  push:\n    branches: [main]\n  pull_request:\n"
        not in source
    ):
        raise ValueError("PR and main test gates mandatory")
    if "\npermissions:\n  contents: read\n" not in source:
        raise ValueError("Read-only CI token required")
    if "\nconcurrency:\n" not in source or (
        "  cancel-in-progress: true\n" not in source
    ):
        raise ValueError("CI cancellation policy missing")
    if ("php-version: "+chr(36)+"{{ matrix.php }}") not in source or (
        "matrix:\n        php: ['8.3','8.4']" not in source
    ):
        raise ValueError("Both supported PHP versions mandatory")
    prev=-1
    for name in REQUIRED:
        entry="          php tests/"+name
        if source.count(entry+"\n")!=1 or not (
            ROOT/"tests"/name
        ).is_file():
            raise ValueError("Missing required regression "+name)
        pos=source.index(entry)
        if pos<=prev:
            raise ValueError("Regressions out of documented dependency order")
        prev=pos
    if source.count("          "+SELF+"\n")!=1:
        raise ValueError("Regression contract sentinel missing")
    if "runs-on:" not in source or "actions/checkout@v4" not in source:
        raise ValueError("Actual CI job removed")

def main()->None:
    source=WORKFLOW.read_text("utf-8")
    verify(source)
    mutants=[]
    for name in REQUIRED:
        mutants.append(source.replace("          php tests/"+name+"\n",""))
        mutants.append(source.replace("          php tests/"+name+"\n",
                                      "          echo skipped-"+name+"\n"))
    mutants.extend([
        source.replace("\n  pull_request:\n","\n  workflow_dispatch:\n"),
        source.replace("    branches: [main]\n",""),
        source.replace("  contents: read\n","  contents: write\n"),
        source.replace("  cancel-in-progress: true\n",
                       "  cancel-in-progress: false\n"),
        source.replace("php: ['8.3','8.4']","php: ['8.4']"),
        source.replace("          "+SELF+"\n",""),
    ])
    for i,mutation in enumerate(mutants):
        try:verify(mutation)
        except ValueError:continue
        raise AssertionError(f"Unsafe CI mutation {i} was accepted")
    assert len(mutants)==14
    print("F1-214: 4 permanent social gates; 14 unsafe workflow changes denied.")
    print("No public endpoint, real service, CRM, client acceptance or deploy.")

if __name__=="__main__":
    main()
