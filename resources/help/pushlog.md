# Push notifications

A record of every push notification the site tried to send to a browser or phone, and what happened to it. Use it to answer "why did this person not get the notification?".

## What you can do here

- See the recent attempts, newest first, with a summary of the last 7 days above them.
- **Filter** to one account, or to failures only.
- **Open** an attempt to see what was sent, what the push service answered and what that means, the device it went to, and the other recent attempts to that device.
- **Send a test** to the same device from an attempt's page. The page then shows whether the device itself confirmed that the test arrived.

## Outcomes

| Outcome | Meaning |
|---|---|
| Delivered | The push service accepted it for that browser. Whether it was shown is up to the device. |
| Subscription gone | The browser no longer accepts notifications from the site, so the subscription was removed. The person must allow notifications again on that device. |
| Not sent | Nothing was sent, usually because the account has no browser subscribed. |
| Push service busy | The push service could not take it at that moment. The next notification is tried normally. |
| Failed / Never reached a server | The push service refused it, or the request never got through (network, DNS or firewall). |

## Good to know

- The log is read-only: a notification cannot be unsent.
- "Not sent" rows are shown on purpose: no subscribed browser is the most common reason someone sees nothing.
- "Delivered" means the push service accepted it. If the person still saw nothing, send a test: if the device confirms the test, the problem is on the device (notification settings, do-not-disturb); if it does not, the subscription is the likely cause.
