# AWS Test Deployment

This guide installs and updates the Maple Grove OpenEMR Education Module on a **disposable AWS EC2 clone** of the class OpenEMR environment.

The current process is a proof/repeatable test deployment, not the final persistent packaging design.

## Repository

```text
https://github.com/CabbageCanFly/maple-grove-openemr-education-module.git
```

Current analytics development branch:

```text
feat/activity-analytics-mvp
```

Until that branch is merged into `main`, clone/pull it explicitly.

## 1. Connect to the EC2 Instance

```bash
ssh -i /path/to/key.pem username@EC2-IP-ADDRESS
```

## 2. Identify the OpenEMR Container

```bash
sudo docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
```

Confirm the container using:

```text
openemr/openemr:7.0.2
```

The disposable AWS clones used during development commonly used:

```text
lightsail_openemr_1
```

Use the actual container name on the target instance.

The commands below use `lightsail_openemr_1` as the example.

## 3. Fresh Clone of the Repository

```bash
cd ~

git clone \
  -b feat/activity-analytics-mvp \
  --single-branch \
  https://github.com/CabbageCanFly/maple-grove-openemr-education-module.git

cd ~/maple-grove-openemr-education-module
git status
```

Expected branch:

```text
feat/activity-analytics-mvp
```

After the analytics branch is merged into `main`, the branch flags can be removed for normal production cloning.

## 4. Copy the Module into the Running OpenEMR Container

AWS module destination:

```text
/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

Copy:

```bash
sudo docker cp \
  ~/maple-grove-openemr-education-module/. \
  lightsail_openemr_1:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

`git clone` or `git pull` changes only the repository on the EC2 host.

Because this proof deployment does not mount that repository directly into OpenEMR, **`docker cp` is also required** before the running OpenEMR container sees the new files.

## 5. Verify the Module Files

```bash
sudo docker exec lightsail_openemr_1 sh -lc '
MODULE=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module

cat "$MODULE/info.txt"

test -f "$MODULE/public/education-dashboard.php" &&
test -f "$MODULE/public/manage-education-users.php" &&
test -f "$MODULE/public/activity-explorer.php" &&
test -f "$MODULE/src/EducationAnalytics.php" &&
test -f "$MODULE/table.sql" &&
echo "Module copy successful."
'
```

## 6. Fresh Module Installation

In OpenEMR:

```text
Modules
-> Manage Modules
-> Unregistered
-> Register
-> Install
-> Enable
```

Then:

```text
Administration
-> Config
-> Maple Grove Education
```

Enable:

```text
Enable Education Dashboard menu item
```

Save.

Log out/in if the Modules menu does not refresh immediately.

The dashboard should appear at:

```text
Modules
-> Education Dashboard
```

## 7. What the Install Step Creates

`table.sql` creates the module-owned tables:

```text
mod_maple_grove_education_users
mod_maple_grove_education_events
```

It also ensures these performance indexes exist on OpenEMR's existing audit `log` table:

```text
idx_maple_grove_log_user_date
idx_maple_grove_log_user_event_date
idx_maple_grove_log_date_user
idx_maple_grove_log_user_patient_date
```

The index setup checks `information_schema.statistics` first, so reinstalling on a test database that already has the same Maple Grove index names should not create duplicate-index errors.

The indexes improve analytics lookup performance; they do not change existing audit records.

## 8. Verify the Automatic Index Setup

After a fresh install, verify the Maple Grove indexes:

```bash
sudo docker exec lightsail_openemr_1 php -r '
require "/var/www/localhost/htdocs/openemr/sites/default/sqlconf.php";

$db = new mysqli($host, $login, $pass, $dbase, (int) ($port ?? 3306));

$result = $db->query("SHOW INDEX FROM log");

while ($row = $result->fetch_assoc()) {
    if (strpos($row["Key_name"], "idx_maple_grove_") === 0) {
        echo $row["Key_name"]
            . " | " . $row["Seq_in_index"]
            . " | " . $row["Column_name"]
            . PHP_EOL;
    }
}
'
```

Expected index/column sequences:

```text
idx_maple_grove_log_user_date | 1 | user
idx_maple_grove_log_user_date | 2 | date

idx_maple_grove_log_user_event_date | 1 | user
idx_maple_grove_log_user_event_date | 2 | event
idx_maple_grove_log_user_event_date | 3 | date

idx_maple_grove_log_date_user | 1 | date
idx_maple_grove_log_date_user | 2 | user

idx_maple_grove_log_user_patient_date | 1 | user
idx_maple_grove_log_user_patient_date | 2 | patient_id
idx_maple_grove_log_user_patient_date | 3 | date
```

A genuinely fresh disposable clone is the strongest test because none of the Maple Grove indexes should already exist before installation.

## 9. Configure Education Users

Open:

```text
Modules
-> Education Dashboard
-> Manage Education Users
```

Recommended validation:

1. confirm users are ordered username A-Z;
2. use search to find a user;
3. test **Select Visible Students**;
4. test **Clear Visible Students**;
5. confirm bulk selection affects **Track as Student** only;
6. set at least one analytics viewer;
7. save using the top or bottom **Save Education Users** button.

For a class environment, deliberately decide which users should be tracked before bulk-saving every account.

## 10. Analytics Validation

### Dashboard

Test:

- Today;
- Last 7 Days;
- Last 30 Days;
- Custom Dates;
- meaningful scope;
- all successful audit scope;
- cohort view;
- tracked-student personal view.

### Activity Explorer

Test:

- multiple students;
- student search;
- multiple activity types;
- patient search;
- Patient Chart Sessions;
- 25/50/100 requested rows;
- Older/Newer navigation;
- full student names;
- patient names/IDs;
- patient click opens an internal OpenEMR patient Dashboard tab.

Remember: requested row count is not a strict guarantee for every normalized audit query because raw rows can collapse into grouped activities and request-time scanning is intentionally bounded.

## 11. Updating an Already Installed AWS Test Clone

For normal PHP/UI code changes:

```bash
cd ~/maple-grove-openemr-education-module

git pull origin feat/activity-analytics-mvp
```

Then:

```bash
sudo docker cp \
  ~/maple-grove-openemr-education-module/. \
  lightsail_openemr_1:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

Refresh OpenEMR.

A module reinstall is **not normally required** for PHP-only changes.

### When reinstall/testing is required

A `table.sql` change does not execute just because Git was pulled or files were copied.

When validating schema/install changes:

- use a disposable clone;
- run the module Install workflow;
- verify expected tables/indexes afterward.

Avoid uninstall/reinstall cycles on the official shared instance unless the impact has been tested.

## 12. Database Compatibility Warning

Do not assume the AWS database supports every SQL feature available in local MariaDB 10.11.

A previous implementation using:

```text
WITH
LAG()
OVER()
```

worked locally but failed on an AWS clone.

The current analytics code intentionally uses more conservative SQL and performs some grouping in PHP.

Test meaningful SQL changes on the disposable AWS clone before the shared instance.

## 13. Troubleshooting

### Git pull succeeded but OpenEMR still shows old code

Cause:

```text
the EC2 host repo changed, but the running OpenEMR container did not
```

Fix:

```bash
sudo docker cp \
  ~/maple-grove-openemr-education-module/. \
  lightsail_openemr_1:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/maple-grove-openemr-education-module
```

### Module code copied but new tables/indexes are missing

`table.sql` is install-time SQL. Copying the file does not execute it.

Use a disposable environment to test the module Install path.

### Analytics are unexpectedly slow

Check that the Maple Grove audit indexes exist.

Also confirm:

- a sensible date range is selected;
- the request is not using raw/all-history scope unnecessarily;
- the query is using the intended tracked-student filters.

### SQL works locally but fails on AWS

Suspect database-version/syntax differences first.

Avoid newer CTE/window features unless the AWS target has been explicitly verified to support them.

### Patient links open incorrectly

Current intended behavior is an internal OpenEMR patient Dashboard tab, not a separate browser tab.

Preserve that behavior during future changes.

## Deployment Limitation

`docker cp` places the module inside the writable layer of the running OpenEMR container.

It normally survives restart of that same container, but the files may disappear if the container is removed/recreated.

Long-term deployment should use one of:

- a derived/maintained OpenEMR image containing the module;
- a persistent bind mount;
- a persistent Docker volume;
- another repeatable deployment system managed outside the container.

Until that is implemented, always retain the Git repository as the source of truth and test deployment changes on disposable clones before the shared class instance.
