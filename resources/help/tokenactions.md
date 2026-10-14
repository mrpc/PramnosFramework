# Token actions

An audit log of every API call made with a user's token: which endpoint was called, by whom, through which token, with what result and how long it took.

## What you can do here

- **Browse** the calls, newest first.
- **Filter** by token, user, HTTP status code, or a date range.
- **Open** a single call to see its full details.
- **Stats**: the most-called endpoints, the error rate, and response times (average and slow-end percentiles) for the last 24 hours.
- **Export** the filtered calls as a CSV file, for audits or for analysis in a spreadsheet.

## Good to know

- The log is read-only on purpose. Nothing can be edited or deleted here, so it can be trusted as an audit trail.
- Apply filters before exporting: the export follows the current filters and has a maximum number of rows.
- Status codes in the 400s usually mean the caller sent something wrong or was not allowed; 500s mean the site failed to handle the call.
- Only calls made with a user token appear here.

## Common questions

- **An integration is misbehaving.** Filter by its user or token and look at the status codes and errors of its recent calls.
- **Who used this token?** Open the token on the Tokens screen; it shows the owner and its latest calls.
