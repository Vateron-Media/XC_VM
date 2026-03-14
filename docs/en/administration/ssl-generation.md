<h1 align="center">Generating SSL Certificate for Nginx in XC_VM</h1>

<p align="center">
  This guide explains how to <b>create a self-signed SSL certificate</b> to enable secure HTTPS connections for the built-in Nginx server in the <b>XC_VM</b> project.
</p>

---

## Navigation

* [Introduction](#introduction)
* [Configuration Location](#configuration-location)
* [Step 1. Generate Private Key](#step-1-generate-private-key)
* [Step 2. Create server.cnf Configuration File](#step-2-create-servercnf-configuration-file)
* [Step 3. Generate Self-Signed SSL Certificate](#step-3-generate-self-signed-ssl-certificate)
* [Final Files](#final-files)
* [Result](#result)
* [Notes](#notes)

---

## Introduction

**SSL (Secure Sockets Layer)** encrypts the connection between client and server, ensuring data confidentiality and user trust.  
This tutorial shows how to create a **self-signed SSL certificate** for the embedded **Nginx** server in the **XC_VM** project.

---

## Configuration Location

All SSL-related files (key, certificate, and config) are stored in:

```bash
/home/xc_vm/bin/nginx/conf
```

Navigate to this directory before proceeding:

```bash
cd /home/xc_vm/bin/nginx/conf
```

---

## Step 1. Generate Private Key

Generate a **2048-bit RSA private key**:

```bash
openssl genrsa -out server.key 2048
```

After execution, the file `server.key` will appear — this is your **private key**.  
Keep it **strictly confidential** — it is used to sign the SSL certificate.

---

## Step 2. Create server.cnf Configuration File

Create a configuration file containing certificate parameters:

```bash
cat > server.cnf << EOF
[req]
distinguished_name = req_distinguished_name
x509_extensions = v3_req
prompt = no

[req_distinguished_name]
C = RU
ST = Moscow
L = Moscow
O = XC_VM
OU = XC_VM
CN = XC_VM

[v3_req]
keyUsage = keyEncipherment, dataEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = XC_VM
EOF
```

**Parameter explanation:**

| Field   | Value    | Purpose                          |
| ------- | -------- | --------------------------------- |
| `C`     | RU       | Country                           |
| `ST`    | Moscow   | State/Province                    |
| `L`     | Moscow   | City/Locality                     |
| `O`     | XC_VM    | Organization                      |
| `OU`    | XC_VM    | Organizational Unit               |
| `CN`    | XC_VM    | Common Name (primary hostname)    |
| `DNS.1` | XC_VM    | Subject Alternative Name (SAN)    |

> **Tip:** For real domain names, replace `DNS.1 = XC_VM` with your actual domain (e.g., `DNS.1 = panel.example.com`) to avoid browser warnings.

---

## Step 3. Generate Self-Signed SSL Certificate

Generate the certificate using the private key and configuration file:

```bash
openssl req -new -x509 -key server.key -out server.crt -days 3650 -config server.cnf
```

**Explanation:**

* `-new -x509` — creates a new self-signed certificate
* `-days 3650` — certificate validity period (10 years)
* `-config server.cnf` — uses the custom configuration
* Result: `server.crt` file containing the public certificate

---

## Final Files

After completing all steps, the following files should be present in `/home/xc_vm/bin/nginx/conf`:

| File          | Purpose                          |
| ------------- | --------------------------------- |
| `server.key`  | Private key                       |
| `server.crt`  | Self-signed SSL certificate      |
| `server.cnf`  | Certificate configuration file   |

---

## Result

Your **XC_VM Nginx server** is now accessible via **HTTPS** using the newly created self-signed certificate.  
Browsers will display a “not trusted” warning — this is expected behavior for self-signed certificates.

---

## Notes

* Self-signed certificates are suitable **for internal use or testing only**.
* For public-facing domains, use certificates from trusted CAs (e.g., [Let's Encrypt](https://letsencrypt.org/)).
* If you change the domain/hostname (`CN` or `DNS.1`), you **must regenerate** the certificate.
* To inspect the generated certificate:

```bash
openssl x509 -in server.crt -text -noout
```

---