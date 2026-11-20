# Connectors Filament

One-to-one Filament 5 plugin for `module-automation-connectors`. The host panel attaches it explicitly; authorization and domain rules remain in the core module.

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `liberusoftware/automation-connectors`.
- Actual Composer identity: `liberusoftware/automation-connectors-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/automation/filament/connectors.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Automation](https://github.com/liberusoftware/project-automation-laravel-filament): AI gateways, approvals, rules and integrations.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
