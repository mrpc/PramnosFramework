# AI settings

Every language-model setting of the site in one place: which AI providers it may use and their keys, which provider each part of the application uses, what calls cost, and what has been spent or has failed.

## What you can do here

- **General**: choose the default provider, set how long to wait for an answer, and whether every call is logged.
- **API keys**: save or remove each provider's key, and **Test the key** to check that the provider accepts it.
- **Features**: for each part of the application that uses AI, choose the provider, model, temperature, a token budget and whether the model may reason first. Empty fields follow the provider's own settings.
- **Prices**: the cost per million tokens for each model, used to work out what calls cost. You can go back to the framework's prices.
- **OpenAI billing**: with an OpenAI admin key, see what OpenAI billed this month and the remaining balance worked out from the top-ups you record.
- **Calls**: the recent calls with their cost, tokens and outcome, or only the problems. Open a call to see the full request, reply, usage and error.

## Good to know

- Saved keys are never shown again. The field stays empty and only says whether a key is stored. Submitting it empty keeps the stored key; removing it is a separate checkbox.
- If the page warns that keys are stored unencrypted, the site has no encryption key configured. Ask a developer to set one before saving keys.
- A model with no price is not costed, and its calls are counted separately.
- Some providers are marked as needing agreement before data is sent to them. People are then asked to agree before their questions go to that provider.
- "Let the model reason first" improves some answers but is billed.
