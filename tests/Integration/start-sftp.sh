#!/usr/bin/env bash
# Starts the OpenSSH SFTP server the integration tests use, generating throwaway keys first.
#   sftp://kipchak@127.0.0.1:52222, password "kipchak-password", or the keys in .keys/:
#     id_ed25519      no passphrase
#     id_rsa          passphrase "kipchak-passphrase"
#   The user is chrooted to its home; the writable directory is /upload. The image is built from Dockerfile.
#   .keys/fingerprints.txt holds the server's host key fingerprints.
# Stop it with: docker stop kipchak-sftp
set -euo pipefail
cd "$(dirname "$0")"
IMAGE=kipchak-sftp-test
KEYS="$PWD/.keys"
rm -rf "$KEYS"
mkdir -p "$KEYS"
docker build -q -t "$IMAGE" . >/dev/null

ssh-keygen -q -t ed25519 -N '' -C kipchak-sftp-host -f "$KEYS/ssh_host_ed25519_key"
ssh-keygen -q -t rsa -b 3072 -N '' -C kipchak-sftp-host -f "$KEYS/ssh_host_rsa_key"
ssh-keygen -q -t ed25519 -N '' -C kipchak-client -f "$KEYS/id_ed25519"
ssh-keygen -q -t rsa -b 3072 -N 'kipchak-passphrase' -C kipchak-client-encrypted -f "$KEYS/id_rsa"
cat "$KEYS/id_ed25519.pub" "$KEYS/id_rsa.pub" > "$KEYS/authorized_keys"
for key in ssh_host_ed25519_key ssh_host_rsa_key; do
  ssh-keygen -lf "$KEYS/$key.pub" | awk '{print $2}'
done > "$KEYS/fingerprints.txt"
chmod 600 "$KEYS"/ssh_host_*_key "$KEYS"/id_*
chmod 644 "$KEYS"/*.pub "$KEYS/authorized_keys" "$KEYS/fingerprints.txt"

docker rm -f kipchak-sftp >/dev/null 2>&1 || true
# Host keys and authorised keys are mounted, so the host key survives the container restarts the tests make.
docker run -d --name kipchak-sftp -p 52222:22 -v "$KEYS:/etc/ssh/keys:ro" "$IMAGE" >/dev/null

until ssh-keyscan -p 52222 127.0.0.1 2>/dev/null | grep -q ssh-ed25519; do sleep 0.2; done
echo "SFTP server ready"
