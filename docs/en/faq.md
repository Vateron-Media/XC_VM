<h1 align="center">❓ FAQ — Frequently Asked Questions</h1>

<p align="center">
  Here you’ll find answers to the most common questions and issues when working with <b>XC_VM</b>.
</p>

---

## ⚙️ Stream Issues

<details>
<summary><b>❌ My stream doesn’t start on MAIN or LB</b></summary>

---

### 🔍 Diagnostics

Connect to your server console and run the following command:

```bash
sudo -u xc_vm /home/xc_vm/bin/php/bin/php /home/xc_vm/includes/cli/monitor.php 291
````

> 🧩 Where `291` is your **stream ID** (replace it with your own).

---

### 📄 What the command does

The **monitor.php** script tries to start the stream manually and displays an error if it fails.

---

### ⚠️ Possible causes

#### 1️⃣ Missing system libraries

If the output contains an error like:

```
error while loading shared libraries: libxyz.so.1: cannot open shared object file
```

Install the missing library with:

```bash
sudo apt install <library_name>
```

After installation, rerun the test.

> 💬 Let me know if a library needs to be added to the installation script.

---

#### 2️⃣ Error not related to libraries

If the error is of another type — send its output so I can help diagnose it.

---

### 🧾 Summary

1. Run the diagnostic command.
2. Check for any errors.
3. Install missing libraries if necessary.
4. Report any other errors for further analysis.

---

</details>

---

📘 *This page is updated over time. If you discover a new common issue — please suggest it in [Issues](https://github.com/Vateron-Media/XC_VM/issues).*

---

