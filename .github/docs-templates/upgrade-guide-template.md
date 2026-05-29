# Upgrade Guide Template

Use this template for every major/minor framework upgrade.

## Scope

- From: `vA.B`
- To: `vX.Y`
- Audience: application maintainers and DevOps

## Preconditions

- Backup completed and verified
- Staging environment mirrors production
- Rollback plan documented

## Breaking Changes

- Breaking change 1 and impact
- Breaking change 2 and impact

## Step-by-Step Upgrade

1. Update framework dependency.
2. Run required migration commands.
3. Apply configuration changes.
4. Clear caches and rebuild generated assets.
5. Run full test suite.

## Database Migration Plan

- Required migrations
- Long-running migrations and downtime considerations
- Data backfill steps

## Configuration Changes

- New settings keys
- Deprecated or removed settings
- Environment variable changes

## Validation Checklist

- Authentication/login flow works
- API smoke tests pass
- Critical background jobs process successfully
- Monitoring and logs show no regressions

## Rollback Plan

1. Restore previous release artifact.
2. Revert configuration toggles.
3. Revert database changes (if supported) or restore snapshot.

## Post-Upgrade Observability

- Metrics to watch for 24h
- Error signatures to alert on
- Incident owner and escalation path
