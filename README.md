# Field: Cloud Storage

This extension allows you store your uploads in the "Cloud" using [Rackspace's Cloud Files](http://www.rackspace.com/cloud/files/) product. While the extension is currently tied to Rackspace, it leverages their [PHP OpenCloud SDK](https://github.com/rackspace/php-opencloud) which theoretically means it can work on other providers.

## Installation

1. Upload the `/cloudstoragefield` folder to your Symphony `/extensions` folder

2. If you are using Git, ensure that you checkout the submodules, running `git submodule update --init` inside the `/cloudstoragefield` folder should do the trick

3. Enable the extension by selecting the "Field: Cloud Storage Field" on the Symphony extensions page, choose Enable from the With Selected menu and then click Apply.

3. Go to your Symphony preferences page to add your Rackspace credentials and select your default container region.

3. You can now add the "Cloud Storage Field" field to your sections and choosing the container to upload the files into.

### Gotchas

- Make sure that your container is Enabled for Public Access (CDN), private containers are not supported by this extension
- You will probably always need to create your containers inside the Rackspace Control Panel. It's just easier :)
- Changing a field's container will not copy existing entries data to the new container

### TODO

- Make faster!
- Better exception handling with Rackspace and reporting this errors back to users
- Allow you to define file TTL on the CDN
- Better abstraction (all hardcoded to use `Providers_Rackspace` at the moment)
- Allow other cloud providers, such as AWS S3 to be used as part of this extension. Developers can then select which provider they'd like a field to register to allowing your site to use both Rackspace and AWS S3

### Credits

Big thumbs up to Will Nielsen, Andrew Shooner, Brian Zerangue, Michael Eichelsdoerfer and Scott Tesoriere for their work in the S3 Upload Field and the Unique Upload Field. It provided a pretty good base for knowing how and when to connect to Rackspace.

