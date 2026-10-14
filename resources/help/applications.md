# Applications

The client applications allowed to use the site's API and sign-in service: mobile apps, partner systems, websites that log in through this site. Each has its own credentials, access rules and limits.

## What you can do here

- **Browse** the registered applications.
- **View** one: its details, Client ID, token counts, recent users, the resources and scopes it has declared, and its webhooks.
- **Create** an application or **edit** one: name, type, redirect addresses, grant types, scopes, token lifetimes, allowed browser origins, allowed and blocked network addresses, rate limits, pagination, and its public and legal information.
- **Rotate the client secret** to issue a new one.
- **See its tokens**, the active sign-ins issued to it.
- **Add, rotate or remove webhook endpoints**: addresses the site notifies when an event happens.
- **Delete** an application.

## Good to know

- The Client ID and client secret are generated when the application is created. Saving changes later never replaces them.
- Store a new secret or webhook signing secret as soon as it is shown; it is shown once. After a rotation the old secret stops working, so update the application's configuration straight away.
- Rotating the client secret does not sign anyone out: existing tokens keep working until they expire.
- Delete switches the application off and revokes all its active tokens, so its users are signed out of it at once. The records are kept.
- *First-party* applications skip the consent screen. Use it only for your own applications.
- A *confidential* client can keep a secret (a server). A mobile or browser app is *public* and cannot.
- Webhook addresses normally must use HTTPS in production.
- If saving reports that access and limits were not saved, the rest of the application was saved; fix the listed fields and save again.
