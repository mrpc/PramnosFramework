# Queue

This page lists the background jobs the site has queued: emails to send, imports, notifications and anything else the application hands to its workers. Use it to see what is waiting, what failed, and to put failed jobs back in line.

## What you can do here

- **Filter** the list by status (pending, processing, completed, failed, deleted) or by job type. The newest jobs are shown first, 50 per page.
- **Retry** a failed job. It goes back to *pending* and a worker picks it up again.
- **Retry All Failed** puts every failed job back to *pending* in one step.
- **Delete** removes a single job from the work queue.
- **Clear** removes every job of one status at once. Only failed, completed or deleted jobs can be cleared; pending and processing jobs are protected.
- **Stats** shows how many jobs are in each status and the average processing time.

## Good to know

- Nothing is erased. Delete and Clear only mark a job as *deleted*, so it stays on record and workers skip it. Filter by *deleted* to see them.
- Retry keeps the attempts count. A job that has already used up its allowed attempts may fail again straight away; fix the cause first.
- Retry clears the stored error message, so read or copy it before retrying if you need it.
- Jobs only run while the queue workers are running. If jobs stay *pending*, check the Services page.

## Common questions

- **A job is stuck in processing.** Its worker may have stopped mid-task. Check the Services page and the worker's log.
- **Can I edit a job's data?** No. This page only retries, deletes and clears.
