# Contributing

Thanks for taking the time to help.

1. Open an issue first for new features or new gateways, so we can agree on the scope.
2. Fork the repository and create a branch from `main`.
3. Add tests for your change. Gateway behaviour is tested with `Http::fake()`; base any fixture on
   the gateway's official documentation and link to it in the driver.
4. Make sure all checks pass:

   ```bash
   composer test
   composer lint:check
   composer analyse
   ```

5. Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat(stripe): ...`,
   `fix: ...`, `docs: ...`) and keep each commit focused.

Please report security issues privately by email to mohammedname2002@gmail.com instead of opening
a public issue.
