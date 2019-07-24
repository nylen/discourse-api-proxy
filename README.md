# discourse-api-proxy

This is a PHP script to simulate more fine-grained authentication capabilities
for the
[Discourse](https://www.discourse.org)
REST API.

[Currently](https://meta.discourse.org/t/cant-generate-multiple-api-keys/21011),
Discourse manages API access through a single master key (and
user-specific keys which may not be adequate for your needs).

If you need more fine-grained authentication, then this script may work for
you.  It stores the master API key in a config file, and allows configuring and
distributing multiple "client keys".

Discourse API clients can then point to this script as if it were the real
Discourse API, and you can give out one of your "client keys" instead of the
master API key.

For each client key, you can define the Discourse API endpoints that clients
are allowed to call, and also a list or pattern of allowed IP addresses for
incoming requests.

## Usage

- Copy `sample-config.php` to `config.php` and fill in the values.
- Host your `config.php`, the `index.php` script and its accompanying
  `.htaccess` file using Apache (or make sure all requests will be routed to
  `index.php` using your server software of choice).
- Configure your Discourse API client(s) to point to this script instead of the
  real Discourse API, and use one of the "client key" values defined in
  `config.php` instead of the real Discourse API key.

## Caveats

At the moment, Discourse API clients **must** authenticate by using the
`api_key` and `api_username` parameters in the **query string** or
**form-encoded `POST` request body**!  Authentication via **request headers**
or **JSON body** is not supported by this script yet!

If you are using Discourse as an SSO provider via the `/session/sso_provider`
endpoint, you **must** configure your client to talk to this endpoint directly
via the Discourse API instead!  This is because this endpoint sets a cookie
for the next step in the login process inside Discourse, and this cookie must
be recognized on the same domain and subdomain as the Discourse install.

Using this script will introduce a mismatch between the forum URL and the forum
API URL, and client code may assume these two base URLs are the same.

Given these caveats, many Discourse clients **will need modification** in order
to work with this script.

## Contributions

Bug reports and change requests via GitHub issues and PRs are welcome.
