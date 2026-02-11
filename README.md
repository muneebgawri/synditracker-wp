# Synditracker: Syndication Monitoring System
**Author**: Muneeb Gawri ([muneebgawri.com](https://muneebgawri.com))

Synditracker is a professional-grade, two-part WordPress syndication monitoring system. It allows network owners to track how their content is being published across partner sites in real-time.

## System Components

### 1. Synditracker Core (Hub)
The central monitoring station. 
- **Role**: Receives reports from Agents, flags duplicates, and provides a dashboard.
- **Key Features**: 
  - Key Management Registry (Generate/Revoke site keys).
  - Advanced Alerting (Discord Webhooks & Multi-Email support).
  - Pulse Integrity Monitoring (Automatic duplicate detection).
- **Slug**: `synditracker`

### 2. Synditracker Agent
The lightweight reporter for partner sites.
- **Role**: Sits on partner sites and reports new posts back to the Hub.
- **Key Features**:
  - Asynchronous reporting (No impact on site performance).
  - Support for Feedzy and WPeMatico.
  - Automatic GUID extraction.
- **Slug**: `synditracker-agent`

---

## Installation

### Core Hub Setup
1. Upload the `synditracker` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Navigate to **Synditracker > Key Management** to generate your first Site Key.
4. Configure your alerts in **Synditracker > Alerting**.

### Agent Setup
1. Upload the `synditracker-agent` folder to the partner site.
2. Activate the plugin.
3. Navigate to **Synditracker Agent** in the sidebar.
4. Enter your **Hub URL** (e.g., `https://hub.example.com`) and the **Site Key** generated in the Hub.

---

## Automatic Updates
This system is configured to receive updates directly from the [synditracker-wp](https://github.com/muneebgawri/synditracker-wp) repository.
- Ensure your server has access to GitHub's API.
- Update notifications will appear in your WordPress dashboard just like standard plugins.

## Developer & Support
Developed by **Muneeb Gawri**. For support or custom integrations, visit [muneebgawri.com](https://muneebgawri.com).

---

&copy; 2026 Muneeb Gawri. All rights reserved.
