# Tokens

The access tokens the site has issued: each one lets an application act for a user until it expires or is revoked. Use this screen to see who is signed in to which application, and to cut access off.

## What you can do here

- **Browse** the active tokens, with the user, application, scope, status and when each was last used.
- **Filter** by user, application or scope.
- **View** a token: whose it is, which application issued it, when and from which address it was last used, how many calls it has made, and the most recent ones.
- **Revoke** a single token.
- **Revoke in bulk** every active token of one user, one application, or one user in one application.
- From a user's page, open **All Tokens** to see that person's tokens in any state and **deactivate** or **delete** them.

## Good to know

- Tokens are never erased. Revoking marks a token as revoked and records when, so the audit trail stays complete.
- Revoking takes effect immediately: the application must ask the person to sign in again.
- Bulk revoke needs at least a user or an application, so the whole site cannot be signed out by accident.
- The site-wide list shows every user's tokens, so it is restricted to senior administrators. A user's own token list is part of the Users screen.
- To stop an application for everyone, delete it on the Applications screen; that revokes all its tokens too.

## Common questions

- **A lost or stolen phone?** Revoke that user's tokens, and consider resetting their password.
- **What did this token do?** Open it, or use the Token actions screen for the full history.
