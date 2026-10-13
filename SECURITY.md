# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: on the repository page, open the **Security** tab and
click **Report a vulnerability**. Describe the issue, the affected version and, if you can,
the steps to reproduce it.

The report stays between you and the maintainer.

## What the plugin does to stay safe

- Every entry point (admin screens, form handlers, REST routes, exports) checks that the
  current user can edit the reviewed content, and forms carry nonces.
- Corrections and notes are stored as plain text and escaped for each output (HTML, XML,
  CSV, JSON, Markdown); CSV cells that a spreadsheet could run as formulas are neutralized.
- Database queries are prepared; exports are streamed and never stored in the uploads
  folder; the plugin makes no external request.
