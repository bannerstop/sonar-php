# Security policy

Please do not open a public issue for security problems. Open the **Security**
tab of this repository and choose **Report a vulnerability**; we will get back
to you and keep you posted until a fix is released.

Security fixes go into the latest release first.

## What the client guarantees

- The token is only sent in the `Authorization` header to the URL you configured
  and is never written to `error_log()` or to the buffer files.
- A broken monitoring never breaks the job: `pulse()` and `run()` do not throw
  because of anything the client does itself.
