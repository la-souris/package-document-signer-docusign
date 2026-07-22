# Contributing

Thanks for your interest in contributing to `la-souris/document-signer-docusign`! Contributions of all
kinds are welcome — bug reports, fixes, documentation, and features.

## Getting started

1. Fork the repository and clone your fork.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Create a branch for your change:
   ```bash
   git checkout -b my-change
   ```

## Development workflow

- Run the test suite before opening a pull request:
  ```bash
  composer test
  ```
- Follow the existing code style (PSR-12). Keep changes focused and small.
- Add or update tests for any behavior you change.
- Update the `CHANGELOG.md` and relevant docs when appropriate.

## Pull requests

- Describe the problem your change solves and how you verified it.
- Reference any related issues.
- Ensure CI is green. Maintainers may request changes before merging.

## Reporting bugs

Open an issue with a clear description, reproduction steps, and the versions of
PHP and this package you are using.

## Security

Please do **not** report security vulnerabilities through public issues. See
[SECURITY.md](SECURITY.md) for how to report them responsibly.

## License

By contributing, you agree that your contributions will be licensed under the
[MIT License](LICENSE).
