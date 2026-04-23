# JoSAA Seat Allotment Analysis Portal

A modern, dynamic data exploration portal to analyze JoSAA seat allocation trends across IITs from 2016-2024. Extract, compare, and uncover admission patterns using the fully integrated MySQL & PHP backend.

### JOSAA Dataset Source
Keep all raw JoSAA cutoff files located in the central drive link.
**[Download Josaa Data CSV Files](https://drive.google.com/drive/folders/1F9bFUp95yQx-fWz-Qqgd2Bkq0J0CAitl)**

---

## How to Run the Project (From Scratch)

This project utilizes a **WAMP/XAMPP stack** (Windows, Apache, MySQL, PHP) wrapped with a Python data processing pipeline.

### Step 1: Install and Start XAMPP Server
1. Download and install **XAMPP**.
2. Open the XAMPP Control Panel.
3. Click **Start** for both **Apache** and **MySQL**.

### Step 2: Setup the Project Directory
Because the project uses PHP, it **must** be served through Apache.
1. Move the `JOSAA-Analysis-Portal` folder into your XAMPP's public HTML directory (`C:\xampp\htdocs\`). 
   - *If it's already in htdocs or you've configured a vhost symlink, skip this.*
2. Ensure the folder is named exactly `JOSAA-Analysis-Portal`.

### Step 3: Seed the Data (`DMBS project data`)
1. Download the CSV files from the Drive link above.
2. Ensure all 56 CSV files (`JoSAA_YYYY_R_ALL_IITS_OR_CR.csv`) are placed directly inside the `DMBS project data` folder inside your project.

### Step 4: Initialize Python Environment & Migrate DB
The python pipeline constructs the MySQL database dynamically.
1. Open up a terminal in your project folder.
2. Activate your Virtual Environment:
   ```bash
   .\venv\Scripts\activate
   ```
3. Install dependencies:
   ```bash
   pip install -r requirements.txt
   ```
4. Run the database migration script. This script will merge all 120,000+ CSV rows and auto-inject them into your MySQL server!
   ```bash
   python process_data.py
   ```

### Step 5: Launch the Application!
Once your database is populated securely:
1. Open any modern web browser.
2. Navigate to: **[http://localhost/JOSAA-Analysis-Portal/](http://localhost/JOSAA-Analysis-Portal/)**
3. The project will communicate natively with your `Jossa` database over the PHP API and immediately render the visualizer dashboards. 

---

## Additional Tools
- **Automated Web Scraper:** Need to grab newer data? Run `python scraper.py` using Chromium to pull live IIT allotment results to your desktop.
