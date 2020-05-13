# SharePoint
💾 Nextcloud SharePoint Backend for External storages

The SharePoint Backend allows administrators to add SharePoint document libraries as folders in Nextcloud. This offers an easy way for users to access SharePoint data in the same place where they find their other files, facilitating collaboration and sharing within and across the borders of the organization. Users can use the desktop client, mobile apps or web interface and comment, tag, share and collaboratively edit files on SharePoint just like with any other data on Nextcloud.

![screenshot](screenshots/configuration.png)

Supports SharePoint 2013, 2016 and SharePoint Online (Office 365). Nextcloud accesses SharePoint through the SharePoint REST API and uses SAML Token authentication, with a fallback to NTLM auth. Nextcloud respects file access permissions associated with its configured user credentials. Versioning and sharing are handled by Nextcloud.

Learn more about External Storage and SharePoint on [https://nextcloud.com/storage/](https://nextcloud.com/storage/)
