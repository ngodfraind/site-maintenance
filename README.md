if using ssh2, the private key must be PEM encoded: openssl rsa -in id_rsa -outform pem > ssh/id_rsa.pem
Also there is a small bug when removing the "oldest file" when the year changes.
