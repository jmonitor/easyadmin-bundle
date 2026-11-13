# Cloudflare token

The Cloudflare tab reads the Cloudflare API with a read-only token of your account. There are two ways to get one: let an AI coding agent create it and configure the bundle, or create it yourself from a pre-filled form.

## With an AI coding agent

Connect the [Cloudflare MCP server](https://github.com/cloudflare/mcp-server-cloudflare) to your agent, for instance with Claude Code:

```bash
claude mcp add --transport http cloudflare https://mcp.cloudflare.com/mcp
```

Then run this prompt from the root of your application:

````text
Set up the Cloudflare tab of jmonitor/easyadmin-bundle in this project.

This prompt may have been run before: never create a second token. Whenever a step below says to
stop, change nothing, tell me what you found and end there; I will take it from there.

1. If JMONITOR_CLOUDFLARE_API_TOKEN already has a value, in .env.local, another .env*.local file or
   the environment, stop: a token is already set up for this application.

2. With the Cloudflare MCP server, find the zone this application is served from and the account
   that owns it. If several zones could match, or none obviously does, ask me which one.

3. List my user API tokens (GET /user/tokens). If one has a name starting with
   "jmonitor EasyAdmin", whatever its zone and status, stop and give me its name, status and
   creation date: its value cannot be read back. If the list cannot be read, do not create a
   token: give me the link of step 4 instead.

4. Create a user API token (POST /user/tokens) named "jmonitor EasyAdmin - <zone name>", with
   read permissions only, never edit:
   - a policy on that zone only: Zone Read, Zone Settings Read, Analytics Read,
     SSL and Certificates Read, DNS Read;
   - a policy on that account only: Account Settings Read, Account Analytics Read,
     Workers R2 Storage Read, Images Read.
   Take the permission group ids from GET /user/tokens/permission_groups, matching these names.
   If one of them does not exist, leave it out and tell me. Only Zone Read is required.
   If the MCP server is not allowed to create tokens, do not look for another way: give me this
   link, tell me to restrict the zone and account resources to the ones you found, and wait for me
   to paste the token:
   https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22zone%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22zone_settings%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22analytics%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22ssl_and_certificates%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22account_settings%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22account_analytics%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22workers_r2%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22images%22%2C%22type%22%3A%22read%22%7D%5D&accountId=%2A&zoneId=all&name=jmonitor%20EasyAdmin

   Send the creation request once: if it fails or its answer is unclear, list the tokens again
   before anything else, and stop if a "jmonitor EasyAdmin" token now exists.

5. Check the token with GET https://api.cloudflare.com/client/v4/user/tokens/verify, sent with the
   new token as bearer: its status must be "active".

6. Store it in .env.local as JMONITOR_CLOUDFLARE_API_TOKEN, never in a committed file, and do not
   repeat its value in your answers. Declare the variable empty in .env, so that the other
   environments know it exists.

7. In config/packages/jmonitor_easyadmin.yaml (create it, or merge into it), set
   components.cloudflare.api_token to '%env(JMONITOR_CLOUDFLARE_API_TOKEN)%' and
   components.cloudflare.zone to the zone name. Run bin/console debug:config jmonitor_easyadmin
   to check that it loads.

8. Tell me whether Web Analytics is enabled on the zone: the Web Analytics section of the tab
   needs it. Do not enable it yourself, it adds a script to the pages of the site.

9. Remind me to set the same environment variable on every server that runs the application:
   .env.local only exists on this machine.
````

## By hand

Open the [pre-filled token form](https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22zone%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22zone_settings%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22analytics%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22ssl_and_certificates%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22account_settings%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22account_analytics%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22workers_r2%22%2C%22type%22%3A%22read%22%7D%2C%7B%22key%22%3A%22images%22%2C%22type%22%3A%22read%22%7D%5D&accountId=%2A&zoneId=all&name=jmonitor%20EasyAdmin) in the Cloudflare dashboard. It holds the read permissions the tab uses:

| Permission                          | Used by                              |
|-------------------------------------|--------------------------------------|
| Zone › Zone › Read                  | required: the zone and its status    |
| Zone › Zone Settings › Read         | Settings card                        |
| Zone › Analytics › Read             | Traffic, Security and Today sections |
| Zone › SSL and Certificates › Read  | Certificate card                     |
| Zone › DNS › Read                   | DNS records count                    |
| Account › Account Settings › Read   | Web Analytics section                |
| Account › Account Analytics › Read  | Web Analytics and Storage sections   |
| Account › Workers R2 Storage › Read | R2 card                              |
| Account › Images › Read             | Images card                          |

Restrict *Zone Resources* to your zone and *Account Resources* to its account before creating the token, then store it in the `JMONITOR_CLOUDFLARE_API_TOKEN` environment variable.

A permission left out only hides what needs it; a card whose permission is missing says which one. The Web Analytics section also needs Web Analytics enabled on the zone (Cloudflare dashboard → Web Analytics).
