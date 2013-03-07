# Field: Cloud Storage

This extension allows you store your uploads in the "Cloud" using [Rackspace's Cloud Files](http://www.rackspace.com/cloud/files/) product. While the extension is currently tied to Rackspace, it leverages their [PHP OpenCloud SDK](https://github.com/rackspace/php-opencloud) which theoretically means it can work on other providers.

## Installation

1. Upload the `/cloudstoragefield` folderto your Symphony `/extensions` folder.

2. Enable it by selecting the "Field: Cloud Storage Field", choose Enable from the With Selected menu, then click Apply.

3. Go to your Symphony preferences page to add your Rackspace credentials.

3. You can now add the "Cloud Storage Field" field to your sections, choosing the container to upload the files into.

### TODO

- Make faster!
- Enable meta information for images again
- Better exception handling with Rackspace and reporting this errors back to users
- Allow you to define file TTL on the CDN
- Better abstraction (all hardcoded to use `Providers_Rackspace` at the moment)
- Allow other cloud providers, such as AWS S3 to be used as part of this extension. Developers can then select which provider they'd like a field to register to allowing your site to use both Rackspace and AWS S3

### Limitations

- You will probably always need to create your containers inside the Rackspace Control Panel. It's just easier :)
- Changing a field's container will not copy existing entrie's data to the new container

### Credits

Big thumbs up to Will Nielsen, Andrew Shooner, Brian Zerangue, Michael Eichelsdoerfer and Scott Tesoriere for their work in the S3 Upload Field and the Unique Upload Field. It provided a pretty good base for knowing how and when to connect to Rackspace.

