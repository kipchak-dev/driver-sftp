# An OpenSSH SFTP server for the integration tests: user kipchak, chrooted to /home/kipchak, with /upload writable.
FROM alpine:3.24

RUN apk add --no-cache openssh-server openssh-sftp-server \
	&& adduser -D -h /home/kipchak -s /sbin/nologin kipchak \
	&& echo 'kipchak:kipchak-password' | chpasswd \
	&& chown root:root /home/kipchak && chmod 755 /home/kipchak \
	&& mkdir -p /home/kipchak/upload && chown kipchak:kipchak /home/kipchak/upload \
	&& printf '%s\n' \
		'HostKey /etc/ssh/keys/ssh_host_ed25519_key' \
		'HostKey /etc/ssh/keys/ssh_host_rsa_key' \
		'PasswordAuthentication yes' \
		'KbdInteractiveAuthentication no' \
		'AuthorizedKeysFile /etc/ssh/keys/authorized_keys' \
		'StrictModes no' \
		'Subsystem sftp internal-sftp' \
		'# The tests probe the port and refuse host keys on purpose: do not lock the test client out for it.' \
		'PerSourcePenalties no' \
		'Match User kipchak' \
		'  ChrootDirectory /home/kipchak' \
		'  ForceCommand internal-sftp' \
		'  AllowTcpForwarding no' \
		> /etc/ssh/sshd_config

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D", "-e"]
