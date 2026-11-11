{
    "name": "agentcat/package-probe",
    "description": "A fresh project that installs agentcat/sdk from its dist, as a customer does.",
    "type": "project",
    "license": "proprietary",
    "repositories": [
        {
            "type": "path",
            "url": "__DIST__",
            "options": {
                "symlink": false
            }
        }
    ],
    "require": {
        "agentcat/sdk": "*@dev",
        "mcp/sdk": "__MCP_SDK__"
    },
    "minimum-stability": "__STABILITY__",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {},
        "audit": {
            "ignore": {
                "PKSA-s52d-n7sx-trsg": {
                    "apply": "block",
                    "reason": "Exercise the supported mcp/sdk 0.8.1 release against a loopback-only collector without suppressing audit reports."
                }
            }
        },
        "sort-packages": true
    }
}
