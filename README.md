# form_brevo

EXT:form finisher `BrevoSubscribe`: creates or updates a Brevo contact through the
Brevo API v3, puts it on lists and, optionally, sends Brevo's double opt-in mail.

It replaces embedded Brevo HTML/iframe forms. The browser only talks to TYPO3, so
nothing has to be consented to in the cookie banner, and the form uses the site's
own markup, validators and spam protection.

## API key

Extension configuration `form_brevo.apiKey`, or the environment variable
`BREVO_API_KEY` if that is empty. Keep the key out of form definitions and
repositories. Brevo: *SMTP & API > API keys*.

## Finisher options

```yaml
finishers:
  -
    identifier: BrevoSubscribe
    options:
      listIds: '12'               # comma-separated or YAML list
      emailField: email
      attributes:                 # Brevo attribute => value
        VORNAME: '{firstName}'
        NACHNAME: '{lastName}'
        OPT_IN: '{privacy}'       # a checked Checkbox is sent as true
        SOURCE: 'cpid-002'        # literal values work too
      updateExisting: true
      doubleOptIn: false
      templateId: 0               # Brevo DOI template, required with doubleOptIn
      redirectionUrl: ''          # page after confirming, required with doubleOptIn
  -
    identifier: Confirmation
```

- Empty values are not sent, so an optional field never blanks an attribute Brevo
  already holds.
- Category attributes (Brevo "Kategorie") expect the numeric option id, so the
  select options in the form must use those ids as values.
- If Brevo rejects the address (400 `invalid_parameter` on the e-mail), the
  visitor gets a message and the following finishers are cancelled. Any other
  failure (key, list, template, outage) is logged and ends in the form's error
  view, so nobody sees a success message for a request that never arrived.
  For lead forms, add an `EmailToReceiver` finisher before this one as backup.

## Tests

```
php phpunit.phar -c packages/form_brevo/phpunit.xml.dist
```

Run from the project root; the bootstrap uses the project's autoloader, which
holds EXT:form.

```
```
