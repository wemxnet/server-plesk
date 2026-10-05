# Plesk

Resell [Plesk](https://www.plesk.com) hosting on WemX. Each order creates a Plesk subscription on a service plan, owned by the customer's Plesk account, and the customer manages it from the order page.

## Features

- Create subscriptions from a Plesk service plan
- Checkout asks for the domain
- Reuse the customer's Plesk account across orders, or create one
- Email the customer their Plesk login and FTP/SSH credentials (editable under Email Templates)
- One-click Plesk login for the customer, and login-as for staff
- Suspend, unsuspend, terminate, service plan changes on upgrade or downgrade, and password changes

## Install

Install from the WemX marketplace, or download `Plesk.zip` from a GitHub release and place the `Plesk` folder at `extensions/Servers/Plesk`. Then enable **Plesk**.

Publishing a release builds `Plesk.zip`. Unzipping it creates a folder named `Plesk`. GitHub's own "Source code" archive still unpacks to `server-plesk-<tag>`. Use `Plesk.zip`.

## Connection

Use the Plesk URL without a port, for example `https://plesk.example.com`, and port `8443`. An API key is preferred: create one on the server with `plesk bin secret_key -c`. The admin username and password also work. Turn SSL verification off for a self-signed certificate.

The extension uses the Plesk REST API (`/api/v2`) and its CLI gateway (`/api/v2/cli`) for service plans, plan switches and one-click login links.

## Packages

Pick the Plesk service plan that sets the subscription limits. Login and password changes can be turned off per package. A password change applies to the customer's Plesk account, so it affects all of their Plesk subscriptions.

## Credits

Based on the MIT-licensed Plesk extension from [Paymenter](https://github.com/Paymenter/Paymenter).
