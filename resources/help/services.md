# Services

The background workers that keep the site running: queue workers, the mail sender, scheduled tasks and any daemon the application registers. Use this page to see whether they are running and to stop, start or restart them.

## What you can do here

- See each **service** with its state (running, stopped, error), process id, uptime and last activity.
- **Stop**, **Start** or **Restart** a single service.
- **Restart all** running services at once, for example after an update.
- Open a service's **Logs** to read the last lines it wrote.
- Manage **pools** (groups of identical workers): add a pool, edit its limits, stop or start it, or remove it.

## Good to know

- These buttons do not launch programs themselves. They leave an instruction that the **supervisor** (the orchestrator process) acts on during its next cycle, usually within seconds. The page shows whether the supervisor is running. If it is not, Start and Restart have no effect.
- Restart is graceful: the worker finishes its current task, exits, and the supervisor starts it again.
- A stopped service stays stopped until you start it. If the message says the supervisor will start it again, the database is missing an update: ask a developer to run the migrations.
- Use Restart all after an update the supervisor cannot detect, such as a changed setting workers read at startup.
- Pool changes apply on the supervisor's next cycle. A newly saved pool shows as waiting until then.
- Removing a pool defined in the application's code returns it to its coded settings.
- Every change is recorded with the name of the administrator who made it.
- *Error* means the service should be running but its process is not.

## Common questions

- **I clicked Start and nothing happened.** Check that the supervisor is shown as running. If not, it must be started on the server (the `pramnos orchestrate` command or its system service).
