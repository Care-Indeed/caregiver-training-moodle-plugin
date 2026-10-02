# moodle-local_caregivertraining

Caregiver annual training for Moodle 5.1 (`local_caregivertraining`). It manages AlayaCare identity binding,
per-learner annual cycles, snapshot-before-reset evidence, time accounting, notifications, certificates and a signed
completion event for the integration adapter.

The adapter owns AlayaCare synchronization, eligibility, date calculations and write-back. This plugin never writes
to AlayaCare directly.

## Requirements

- Moodle 5.1 (`public/` directory layout), PHP 8.3
- `mod_customcert` (MOODLE_500_STABLE), plus core `mod_lesson`, `mod_quiz` and `mod_feedback`

## Installation

Install the plugin at `public/local/caregivertraining`, then run `admin/cli/upgrade.php`.

The CIION Moodle image ([Care-Indeed/moodle](https://github.com/Care-Indeed/moodle)) downloads a pinned tag of this
repository in its `Dockerfile`. To release a change:

1. Bump `$plugin->version` (and `$plugin->release`) in `version.php`.
2. Tag the commit (for example `v0.1.1`) and push the tag.
3. Update the tag in the Moodle repository's `Dockerfile`.

## Documentation

Every endpoint (adapter web services, the outbound completion event, admin pages, the CSV export and the CLI
script) is documented in [docs/api-endpoints.md](docs/api-endpoints.md), including how the web services handle common
provisioning and cycle scenarios.

The design, release-blocking HR decisions, reset proof of concept, and local development setup are in the Moodle
repository:

- [docs/caregiver-training/README.md](https://github.com/Care-Indeed/moodle/blob/main/docs/caregiver-training/README.md)
- [docs/caregiver-training/contracts-v1.md](https://github.com/Care-Indeed/moodle/blob/main/docs/caregiver-training/contracts-v1.md)
  (web services and the `cycle.completed` event)

## Development

Clone this repository next to the Moodle repository, then start the local stack with the plugin mounted from this
checkout:

```sh
git clone https://github.com/Care-Indeed/moodle-local_caregivertraining.git
cd moodle
pnpm run local:up:plugin-dev
pnpm run local:test:setup
pnpm run local:test
```

`amd/build/tracker.min.js` is hand-written. Before release, rebuild it with `grunt amd` in a Moodle development
checkout.

## License

GNU GPL v3 or later. See `LICENSE`.
