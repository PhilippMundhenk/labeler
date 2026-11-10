# Contributing

Bug reports, fixes and small focused features are welcome. Work is tracked in
[GitHub issues](https://github.com/PhilippMundhenk/labeler/issues); open one before a larger change.

## Setup and checks

Everything runs in a development container, so Docker is enough. Details in
[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

    tools/dev.sh composer install && tools/dev.sh npm ci
    tools/dev.sh composer coverage     # PHP tests, 95 % line coverage per file
    tools/dev.sh npm test              # lint, JS unit tests, browser tests, 95 % per file

Every file must stay at or above 95 % line coverage; add tests with the code, not after. Changes
around ptouch-print (options, output parsing, image format) also need the simulator in
`tools/ptouch-sim` updated and the contract test in `tools/contract` passing.

Keep the device side small: no new runtime dependencies on the Pi (no PHP extensions beyond the
standard ones, no npm packages in `public/`), PHP 7.4 compatible syntax.

## Pull requests

Branch off `main` and open a PR; CI runs all tests, the coverage gates, PHP 7.4/8.2/8.4 and the
contract test against ptouch-print 1.6 to 1.9. Commit subjects reference the issue (`... (#12)`),
bodies say what and why. Never commit real labels, photos or other personal data as test
material; tests build their own.

## Releases

Add the notable changes to `CHANGELOG.md`, set the version in `package.json`, `package-lock.json`,
`src/Config.php` and `docs/API.md` (`node tools/check-versions.js --tag vX.Y.Z` checks them), then
`git tag vX.Y.Z && git push --tags`. The release workflow builds
`labeler-X.Y.Z.zip` and publishes a GitHub release with it.
