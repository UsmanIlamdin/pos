# POS Docker — macOS Startup & LAN Access Setup

This guide configures the POS Docker project on macOS so that:

1. Docker Desktop starts automatically when you sign in.
2. The POS Docker Compose stack starts automatically after Docker is ready.
3. The startup script dynamically detects the macOS user's home directory.
4. The Mac keeps a fixed LAN IP through a router/modem DHCP reservation.
5. Other devices such as a cellphone can access the POS application over the local network.

## Project Location

The POS Docker project is expected at:

```text
~/Sites/pos/docker
```

The Docker Compose project name is:

```text
pos
```

---

# 1. Create the POS macOS Startup Script

Create the scripts directory if it does not already exist:

```bash
mkdir -p ~/Sites/pos/docker/scripts
```

Create the startup script:

```bash
nano ~/Sites/pos/docker/scripts/mac-start.sh
```

Add:

```bash
#!/usr/bin/env bash

set -e

DOCKER_DIR="$HOME/Sites/pos/docker"
LOG_FILE="$DOCKER_DIR/mac-start.log"

exec >> "$LOG_FILE" 2>&1

echo "=================================================="
echo "POS Docker startup: $(date)"
echo "User: $(whoami)"
echo "Home: $HOME"
echo "=================================================="

echo "Waiting for Docker Desktop..."

for i in {1..60}; do
    if docker info >/dev/null 2>&1; then
        echo "Docker is ready."
        break
    fi

    echo "Docker not ready yet... attempt $i"
    sleep 2
done

if ! docker info >/dev/null 2>&1; then
    echo "ERROR: Docker Desktop was not ready."
    exit 1
fi

cd "$DOCKER_DIR"

echo "Starting POS Docker stack..."

docker compose --project-name pos up -d

echo "POS Docker stack started."
echo "Finished: $(date)"
```

Make it executable:

```bash
chmod +x ~/Sites/pos/docker/scripts/mac-start.sh
```

## Why `$HOME` is used

The script does not hardcode a username such as:

```text
/Users/usman
```

Instead it uses:

```bash
$HOME
```

macOS automatically resolves this to the currently logged-in user's home directory.

For example:

```text
/Users/usman
```

or another user's home directory.

This makes the script portable between macOS user accounts.

---

# 2. Test the Startup Script

Run:

```bash
~/Sites/pos/docker/scripts/mac-start.sh
```

Check the containers:

```bash
cd ~/Sites/pos/docker

docker compose --project-name pos ps
```

The POS containers should be running.

You can also inspect the startup log:

```bash
cat ~/Sites/pos/docker/mac-start.log
```

---

# 3. Configure Docker Desktop to Start Automatically

Open Docker Desktop.

Go to:

```text
Docker Desktop → Settings → General
```

Enable:

```text
Start Docker Desktop when you sign in to your computer
```

This is required because the LaunchAgent waits for Docker, but Docker Desktop itself must also be configured to launch.

---

# 4. Create the macOS LaunchAgent

Create the LaunchAgents directory:

```bash
mkdir -p ~/Library/LaunchAgents
```

Create the LaunchAgent:

```bash
nano ~/Library/LaunchAgents/com.ghazi.pos.docker.plist
```

Add:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
    "http://www.apple.com/DTDs/PropertyList-1.0.dtd">

<plist version="1.0">
<dict>

    <key>Label</key>
    <string>com.ghazi.pos.docker</string>

    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>-c</string>
        <string>exec "$HOME/Sites/pos/docker/scripts/mac-start.sh"</string>
    </array>

    <key>RunAtLoad</key>
    <true/>

    <key>StandardOutPath</key>
    <string>/tmp/ghazi-pos-docker.log</string>

    <key>StandardErrorPath</key>
    <string>/tmp/ghazi-pos-docker-error.log</string>

</dict>
</plist>
```

### Important

The LaunchAgent also uses `$HOME`, so there is no hardcoded:

```text
/Users/usman
```

in the configuration.

---

# 5. Load the LaunchAgent

Run:

```bash
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.ghazi.pos.docker.plist
```

Check that it is loaded:

```bash
launchctl print gui/$(id -u)/com.ghazi.pos.docker
```

If there is no error, the LaunchAgent is registered.

---

# 6. Test the LaunchAgent Without Restarting the Mac

Trigger it manually:

```bash
launchctl kickstart -k gui/$(id -u)/com.ghazi.pos.docker
```

Then check:

```bash
cd ~/Sites/pos/docker

docker compose --project-name pos ps
```

Check the logs:

```bash
cat ~/Sites/pos/docker/mac-start.log
```

LaunchAgent logs:

```bash
cat /tmp/ghazi-pos-docker.log
cat /tmp/ghazi-pos-docker-error.log
```

---

# 7. Restart Test

Once everything works, restart the Mac:

```bash
sudo shutdown -r now
```

After signing in:

1. Docker Desktop should start automatically.
2. The LaunchAgent should run.
3. The script waits for Docker Engine.
4. The POS Compose stack starts with:

```bash
docker compose --project-name pos up -d
```

Verify:

```bash
docker compose --project-name pos ps
```

---

# 8. Find the Mac's Current LAN IP

For Wi-Fi on most Macs:

```bash
ipconfig getifaddr en0
```

Example:

```text
192.168.100.190
```

If `en0` does not return an IP, identify the Wi-Fi interface:

```bash
networksetup -listallhardwareports
```

You can also inspect all interfaces:

```bash
ifconfig | grep "inet "
```

Do not use:

```text
127.0.0.1
```

That is the Mac's loopback address and cannot be used by your cellphone.

---

# 9. Find the Router/Modem IP

Run:

```bash
route -n get default | grep gateway
```

Example:

```text
gateway: 192.168.100.1
```

The router/modem administration page will commonly be:

```text
http://192.168.100.1
```

The exact address depends on your network.

---

# 10. Reserve a Static LAN IP in the Router/Modem

A normal DHCP IP can change.

For example, the Mac may currently have:

```text
192.168.100.190
```

but after a router restart or network reconnect it could receive another address.

The recommended solution is a **DHCP reservation** in the router/modem.

This makes the router consistently assign the same IP to your Mac.

## 10.1 Get the Mac's Wi-Fi MAC Address

Run:

```bash
ifconfig en0 | grep ether
```

Example:

```text
ether aa:bb:cc:dd:ee:ff
```

If Wi-Fi is not `en0`, use the correct interface identified with:

```bash
networksetup -listallhardwareports
```

## 10.2 Open the Router/Modem Admin Page

Open the gateway address found earlier, for example:

```text
http://192.168.100.1
```

Log in with the router/modem administrator credentials.

## 10.3 Find DHCP Reservation Settings

The exact menu name depends on the router/modem manufacturer.

Look for one of these:

```text
DHCP
DHCP Server
LAN
LAN Settings
Address Reservation
DHCP Reservation
Static Lease
Reserved IP
IP & MAC Binding
```

## 10.4 Add the Mac

Create a reservation using the Mac's Wi-Fi MAC address.

Example:

```text
Device/MAC:
aa:bb:cc:dd:ee:ff

Reserved IP:
192.168.100.190
```

Save/apply the configuration.

### Important

Some routers allow an address from the existing DHCP range to be reserved. Others recommend using an address outside the dynamic DHCP pool.

For example, if the router's DHCP range is:

```text
192.168.100.100 - 192.168.100.200
```

the router may allow:

```text
192.168.100.190
```

as a reservation.

If the router requires reserved addresses outside the DHCP pool, use an appropriate address such as:

```text
192.168.100.220
```

provided that it is within the same LAN subnet and is not already assigned to another device.

Follow the router/modem's own DHCP configuration rules.

---

# 11. Verify the Reserved IP

After saving the router configuration, reconnect the Mac to Wi-Fi or restart the Mac.

Check:

```bash
ipconfig getifaddr en0
```

It should return the reserved address.

For example:

```text
192.168.100.190
```

The IP should now remain the same when the router gives the Mac a new DHCP lease.

---

# 12. Access POS From a Cellphone

Make sure the cellphone is connected to the **same Wi-Fi/LAN** as the Mac.

For example:

```text
Mac:
192.168.100.190
```

Then open on the cellphone:

```text
https://192.168.100.190
```

Your Docker/Traefik configuration must expose the web port to the LAN, for example:

```text
0.0.0.0:80->80/tcp
0.0.0.0:443->443/tcp
```

Check with:

```bash
cd ~/Sites/pos/docker

docker compose --project-name pos ps
```

---

# 13. Test the Mac Firewall if the Phone Cannot Connect

If Docker is running and the phone still cannot access the POS application, check macOS firewall settings.

Go to:

```text
System Settings → Network → Firewall
```

If the firewall is enabled, make sure Docker Desktop is allowed as required.

Also confirm that the phone and Mac are actually on the same LAN.

For example:

```text
Mac:     192.168.100.190
Phone:   192.168.100.xxx
Router:  192.168.100.1
```

The exact phone address will depend on your router.

---

# 14. `ghazi-pos.local` vs IP Address

Your POS project uses:

```text
ghazi-pos.local
```

The simplest LAN test is:

```text
https://192.168.100.190
```

However, using:

```text
https://ghazi-pos.local
```

from a cellphone requires the cellphone to be able to resolve that hostname on the LAN.

If the hostname does not resolve on the phone, continue using the reserved IP or configure local DNS/mDNS for the hostname.

---

# 15. Useful POS Commands

## Start POS manually

```bash
cd ~/Sites/pos/docker
docker compose --project-name pos up -d
```

## Stop POS

```bash
cd ~/Sites/pos/docker
docker compose --project-name pos down
```

## View containers

```bash
cd ~/Sites/pos/docker
docker compose --project-name pos ps
```

## View logs

```bash
cd ~/Sites/pos/docker
docker compose --project-name pos logs -f
```

## View startup script log

```bash
cat ~/Sites/pos/docker/mac-start.log
```

## Restart the POS stack

```bash
cd ~/Sites/pos/docker
docker compose --project-name pos restart
```

## Trigger the macOS LaunchAgent

```bash
launchctl kickstart -k gui/$(id -u)/com.ghazi.pos.docker
```

---

# 16. Complete Startup Flow

After the setup is complete:

```text
                    macOS
                      │
                      ▼
              User signs in
                      │
                      ▼
              Docker Desktop
              starts automatically
                      │
                      ▼
              LaunchAgent runs
                      │
                      ▼
          mac-start.sh starts
                      │
                      ▼
          Wait for Docker Engine
                      │
                      ▼
 docker compose --project-name pos up -d
                      │
          ┌───────────┼───────────┐
          ▼           ▼           ▼
       pos-app      pos-db     pos-traefik
          │
          ▼
   LAN IP of Mac
   192.168.100.190
          │
          ▼
       Cellphone
          │
          ▼
https://192.168.100.190
```

---

# 17. Recommended Final Configuration

Use:

```text
POS Project:
~/Sites/pos/docker

Compose Project:
pos

Startup Script:
~/Sites/pos/docker/scripts/mac-start.sh

LaunchAgent:
~/Library/LaunchAgents/com.ghazi.pos.docker.plist

LAN IP:
192.168.100.190
```

The LAN IP should be configured as a **DHCP reservation in the router/modem**, not simply assumed to be permanent.

The startup script and LaunchAgent should use `$HOME` rather than a hardcoded username so the configuration remains portable.
