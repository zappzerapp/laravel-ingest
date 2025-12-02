# Contributing to Laravel Ingest

First off, thank you for considering contributing to Laravel Ingest! It's people like you that make the open-source
community such an amazing place to learn, inspire, and create.

We welcome contributions of all forms: bug fixes, new features, documentation improvements, or just spotting typos.

## 1. Getting Started

This project is built with **Developer Experience (DX)** in mind. We strongly recommend using the provided Docker setup
to ensure a consistent environment.

### Prerequisites

- Docker & Docker Compose
- Git

### Setup

1. **Fork** the repository on GitHub.
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/zappzerapp/laravel-ingest.git
   cd laravel-ingest
   ```
3. **Start the environment**:
   ```bash
   composer docker:up
   ```
4. **Install dependencies** (inside the container):
   The Docker setup usually handles this, but to be sure:
   ```bash
   docker-compose exec app composer install
   ```

---

## 2. Development Workflow

### Coding Standards

- **PHP Version:** We target **PHP 8.3**. Please use modern features (Types, Enums, Readonly properties) where
  appropriate.
- **Style:** Follow PSR-12 coding standards.
- **Strictness:** We aim for strict typing. Please type-hint arguments and return values.

### Running Tests

We use **Pest PHP** for testing. All new features must be accompanied by tests.

To run the test suite inside the Docker container (recommended):

```bash
# Run all tests
composer docker:test
```

To check code coverage (requires Xdebug, which is included in our Dockerfile):

```bash
# Generate coverage report
composer docker:coverage
```

### Making Changes

1. Create a new **branch** for your feature or fix:
   ```bash
   git checkout -b feature/my-new-feature
   ```
2. Make your changes.
3. **Run tests** frequently to ensure nothing broke.
4. Commit your changes using descriptive commit messages.

---

## 3. Pull Request Process

1. Push your branch to your fork on GitHub.
2. Submit a **Pull Request** to the `main` branch of the original repository.
3. **Description:** Clearly explain what your PR does. Link to any related issues (e.g., "Fixes #123").
4. **CI:** Ensure that all status checks pass.

### What we look for in PRs

- **Tests:** Does the PR include tests for the new functionality or the bug fix?
- **Documentation:** Have you updated the `README.md` or code docblocks if API changes were made?
- **Clean Code:** Is the code readable and maintainable?

---

## 4. Reporting Bugs

If you find a bug, please create an issue on GitHub. Include as much detail as possible:

- A clear title and description.
- Steps to reproduce the issue.
- Code samples or a failing test case (extremely helpful!).
- Your environment details (PHP version, Laravel version).

---

## 5. License

By contributing your code to Laravel Ingest, you grant its use under the **GNU Affero General Public License v3.0 (
AGPL-3.0)**. You acknowledge that your contribution will be part of an open-source project and potentially used by
others under these terms.

---

Thank you for building with us! 🚀