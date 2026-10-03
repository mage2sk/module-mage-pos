# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.13] - 2026-10-03

### Fixed
- Admin delete actions for POS payment methods, quick keys, registers, roles and users accept POST requests with a form key only; the grid Delete links for registers, roles and users now submit by POST like the other grids. A plain GET link can no longer delete records.
