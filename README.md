📊 PolyIntel

PolyIntel is an AI-powered Polymarket intelligence and paper trading platform built with Laravel, Python, PostgreSQL, and Docker.

The goal is simple:

«Find high-quality prediction market opportunities, manage risk, and maximize long-term profitability.»

---

Features

Market Intelligence

- Collect active markets from Polymarket Gamma API
- Track probability changes over time
- Monitor liquidity and volume
- Historical market snapshots
- Market outcome tracking
- Signal generation engine

Signals

- AI probability vs market probability
- Edge calculation
- Confidence score
- Momentum analysis
- Liquidity and volume metrics
- Signal ranking system

Portfolio Manager

Paper trading is designed as a portfolio manager, not simply "buy every signal".

Features:

- Portfolio exposure control
- Maximum concurrent trades
- Reserve cash management
- Position sizing engine
- Signal filtering
- Market cooldown protection
- Duplicate market prevention

Smart Exit Engine

Automatically manages open positions:

- Take Profit
- Stop Loss
- Breakeven protection
- Partial Take Profit
- Smart Exit conditions
- Expiry monitoring
- Momentum deterioration detection
- Volume and liquidity decline detection

Performance Analytics

- ROI
- Win Rate
- Profit Factor
- Portfolio Value
- Unrealized PnL
- Exposure Percentage
- Max Drawdown

---

Architecture

Laravel

Responsible for:

- Dashboard UI
- User management
- Portfolio management
- Paper trading
- Performance analytics
- Settings
- Authentication

Python

Responsible for:

- Market collection
- Snapshot collection
- Statistics collection
- Signal generation
- Data enrichment
- AI probability calculations

PostgreSQL

Stores:

- Markets
- Market snapshots
- Market outcomes
- Signals
- Paper trades
- Trade history
- Portfolio settings

---

Tech Stack

Backend

- Laravel 12
- PHP 8.4
- Python 3.13
- PostgreSQL 15
- Redis 7

Frontend

- Tabler UI
- Bootstrap 5
- Chart.js
- AG Grid

Infrastructure

- Docker
- Docker Compose
- Scheduler Jobs
- Queue Workers

---

Paper Trading Workflow

Signals
    ↓
Signal Ranker
    ↓
Minimum Score Filter
    ↓
Top N Signals
    ↓
Portfolio Constraints
    ↓
Position Sizing
    ↓
Paper Trades
    ↓
Smart Exit Engine
    ↓
Trade History

---

Risk Management

PolyIntel focuses heavily on risk management:

- Position sizing
- Maximum exposure
- Cash reserve
- Take Profit
- Stop Loss
- Breakeven
- Partial exits
- Smart exits

The philosophy is:

«Quality over quantity.»

---

Project Structure

```text
polyintel/
├── laravel/
│   ├── app/
│   ├── database/
│   ├── resources/
│   └── routes/
│
├── python/
│   ├── collectors/
│   ├── models/
│   ├── repositories/
│   ├── services/
│   └── utils/
│
├── docker-compose.yml
└── README.md
```

---

Roadmap

Phase 1

- Database
- Core Services

Phase 2

- Portfolio Manager

Phase 3

- Smart Exit Engine

Phase 4

- Dashboard UI
- Settings UI

Phase 5

- Performance Analytics
- Charts
- Reports

Future

- Multi-account support
- Multi-user support
- Backtesting engine
- AI models
- Auto trading
- Telegram notifications
- Strategy comparison
- Portfolio optimization

---

Disclaimer

PolyIntel is intended for research and educational purposes.

Paper trading performance does not guarantee future results.

Prediction markets involve risk.

Always do your own research.

---

Author

Built with ❤️ by CodeIvan

Indonesia 🇮🇩
