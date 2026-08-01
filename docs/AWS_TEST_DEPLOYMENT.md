# AWS Test Deployment

This guide installs the Maple Grove OpenEMR Education Module on a disposable AWS EC2 clone of the OpenEMR environment.

This is a proof-deployment process, not the final permanent deployment design.

## 1. Connect to the EC2 Instance

```bash
ssh -i /path/to/key.pem username@EC2-IP-ADDRESS
```

## 2. Identify the OpenEMR Container

```bash
sudo docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
```

Note the container running:

```text
openemr/openemr:7.0.2
```

Example container name:

```text
lightsail_openemr_1
```

Use the actual container name from your instance in the following commands.

## 3. Clone the Module Repository

```bash
cd ~

git clone   https://github.com/CabbageCanFly/maple-grove-openemr-education-module.git
```

If the repository is already present:

```bash
cd ~/maple-grove-openemr-education-module
git pull
```

## 4. Copy the Module into OpenEMR

Replace `lightsail_openemr_1` if the container has a different name:

```bash
sudo docker cp   ~/maple-grove-openemr-education-module/.   lightsail_openemr_1:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

## 5. Verify the Module Files

```bash
sudo docker exec lightsail_openemr_1 sh -lc '
MODULE=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module

cat "$MODULE/info.txt"

test -f "$MODULE/public/education-dashboard.php" &&
echo "Module copy successful."
'
```

## 6. Register and Enable the Module

In the OpenEMR website:

```text
Modules
-> Manage Modules
-> Unregistered
-> Register
-> Install
-> Enable
```

## 7. Enable the Dashboard Menu Link

Open:

```text
Administration
-> Config
-> Maple Grove Education
```

Check:

```text
Enable Education Dashboard menu item
```

Click **Save**.

## 8. Reload the OpenEMR Session

Log out of OpenEMR and log back in.

The dashboard should now appear at:

```text
Modules
-> Education Dashboard
```

Clicking it should open the custom dashboard inside an OpenEMR tab.

## Deployment Limitation

This process copies the module into the writable layer of the running OpenEMR container.

It should survive a normal restart of the same container, but it may be lost if the container is deleted and recreated.

A future permanent deployment should use one of these approaches:

- a derived OpenEMR Docker image containing the module;
- a persistent bind mount or Docker volume for the module;
- another repeatable deployment process managed outside the container.
