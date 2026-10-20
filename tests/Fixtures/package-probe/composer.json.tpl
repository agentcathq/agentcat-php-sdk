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
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {},
        "sort-packages": true
    }
}
