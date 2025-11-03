# Deployment Guide

This document explains how to deploy the InterSoccer Product Variations plugin to the dev server.

## Initial Setup

1. **Copy the example configuration file:**
   ```bash
   cp deploy.local.sh.example deploy.local.sh
   ```

2. **Edit `deploy.local.sh` with your credentials:**
   ```bash
   nano deploy.local.sh
   ```
   
   Update these values:
   ```bash
   SERVER_USER="your-ssh-username"
   SERVER_HOST="intersoccer.legit.ninja"
   SERVER_PATH="/var/www/html/wp-content/plugins/intersoccer-product-variations"
   SSH_PORT="22"
   SSH_KEY="~/.ssh/id_rsa"
   ```

3. **Test SSH access:**
   ```bash
   ssh -i ~/.ssh/id_rsa your-username@intersoccer.legit.ninja
   ```

## Usage

### Basic Deployment
Deploy all plugin files to the dev server:
```bash
./deploy.sh
```

### Dry Run (Preview Changes)
See what files would be uploaded without actually uploading:
```bash
./deploy.sh --dry-run
```

### Deploy and Clear Server Caches
Upload files and clear PHP opcache, WooCommerce transients, etc.:
```bash
./deploy.sh --clear-cache
```

### Run Tests Before Deploying
Run PHPUnit tests before deploying (deployment will abort if tests fail):
```bash
./deploy.sh --test
```

### Combine Options
```bash
./deploy.sh --test --clear-cache
```

## What Gets Uploaded

The script uploads all plugin files EXCEPT:
- `.git` directory
- `node_modules/`
- `vendor/`
- `tests/`
- `cypress/`
- `*.log` files
- `deploy.sh` and `deploy.local.sh`
- Composer/NPM config files
- IDE files (`.vscode`, `.idea`, etc.)

## What Gets Excluded

The `rsync` command automatically excludes development files while keeping all production-ready plugin files:
- PHP files (includes, admin, etc.)
- JavaScript files
- CSS files
- Assets (images, fonts, etc.)
- Plugin header file

## Clearing Caches

When using `--clear-cache`, the script will:
1. Upload files to the server
2. Create a temporary PHP script on the server
3. Execute the script to clear:
   - PHP Opcache
   - WooCommerce transients
   - WordPress object cache
4. Delete the temporary script

This ensures your changes are immediately reflected without browser cache issues.

## Testing (Future)

### PHPUnit Tests
To run PHP unit tests before deployment:
```bash
./deploy.sh --test
```

This requires:
```bash
composer install
```

### Cypress Tests (Coming Soon)
Once Cypress is configured, you can run E2E tests:
```bash
npm install
./deploy.sh --test
```

## Troubleshooting

### SSH Connection Issues
If you get permission denied errors:
1. Check your SSH key path in `deploy.local.sh`
2. Verify the key is added to the server:
   ```bash
   ssh-copy-id -i ~/.ssh/id_rsa user@server
   ```

### Upload Fails
If rsync fails:
1. Check the `SERVER_PATH` is correct
2. Verify you have write permissions to the plugin directory
3. Try with `--dry-run` first to see what's being attempted

### Caches Not Clearing
If changes don't appear after deployment with `--clear-cache`:
1. Clear browser cache manually (Ctrl+Shift+R)
2. Check if the server has additional caching layers (CDN, proxy cache)
3. SSH into the server and manually reload PHP-FPM:
   ```bash
   sudo systemctl reload php-fpm
   ```

## Advanced Usage

### Custom rsync Options
Add custom rsync options in `deploy.local.sh`:
```bash
RSYNC_EXTRA_OPTS="--progress --stats"
```

### Deploy Specific Files Only
For quick single-file updates, use rsync directly:
```bash
rsync -avz -e "ssh -p 22 -i ~/.ssh/id_rsa" \
  includes/elementor-widgets.php \
  user@server:/path/to/plugin/includes/
```

### Multiple Environments
Create separate config files for different environments:
```bash
cp deploy.local.sh deploy.staging.sh
cp deploy.local.sh deploy.production.sh
```

Then use:
```bash
source deploy.staging.sh && ./deploy.sh
```

## Security Notes

⚠️ **IMPORTANT:**
- `deploy.local.sh` contains server credentials and is in `.gitignore`
- Never commit `deploy.local.sh` to the repository
- Keep SSH keys secure and use passphrase protection
- Use different credentials for staging vs. production

## CI/CD Integration (Future)

This script can be integrated with GitHub Actions or GitLab CI:

```yaml
# .github/workflows/deploy.yml
name: Deploy to Dev Server

on:
  push:
    branches: [ main ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Deploy
        env:
          SSH_KEY: ${{ secrets.SSH_KEY }}
          SERVER_USER: ${{ secrets.SERVER_USER }}
        run: |
          echo "$SSH_KEY" > /tmp/ssh_key
          chmod 600 /tmp/ssh_key
          ./deploy.sh --test --clear-cache
```

