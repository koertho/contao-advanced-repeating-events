(() => {
  "use strict";

  class RruleBuilder {
    /**
     * @param {HTMLElement} root
     */
    constructor(root) {
      this.root = root;
      this.output = root.querySelector("[data-rrule-output]");

      if (!this.output) {
        return;
      }

      this.fields = {
        freq: root.querySelector('[data-rrule-part="freq"]'),
        interval: root.querySelector('[data-rrule-part="interval"]'),
        byday: Array.from(root.querySelectorAll('[data-rrule-part="byday"]')),
        monthlyMode: root.querySelector('[data-rrule-part="monthly_mode"]'),
        bymonthday: root.querySelector('[data-rrule-part="bymonthday"]'),
        bysetpos: root.querySelector('[data-rrule-part="bysetpos"]'),
        monthlyWeekday: root.querySelector('[data-rrule-part="monthly_weekday"]'),
        bymonth: root.querySelector('[data-rrule-part="bymonth"]'),
        yearlyBymonthday: root.querySelector('[data-rrule-part="yearly_bymonthday"]'),
        endMode: root.querySelector('[data-rrule-part="end_mode"]'),
        count: root.querySelector('[data-rrule-part="count"]'),
        until: root.querySelector('[data-rrule-part="until"]'),
      };

      this.sections = {
        weekly: root.querySelector('[data-rrule-section="weekly"]'),
        monthly: root.querySelector('[data-rrule-section="monthly"]'),
        monthlyMonthday: root.querySelector('[data-rrule-section="monthly-monthday"]'),
        monthlyWeekdaypos: root.querySelectorAll('[data-rrule-section="monthly-weekdaypos"]'),
        yearly: root.querySelector('[data-rrule-section="yearly"]'),
        endCount: root.querySelector('[data-rrule-section="end-count"]'),
        endUntil: root.querySelector('[data-rrule-section="end-until"]'),
      };

      this.parseInitialValue();
      this.render();
      this.bindEvents();
    }

    bindEvents() {
      this.root.addEventListener("change", (event) => {
        if (!(event.target instanceof HTMLElement) || !event.target.matches("[data-rrule-part]")) {
          return;
        }

        this.render();
      });

      this.root.addEventListener("input", (event) => {
        if (!(event.target instanceof HTMLElement) || !event.target.matches("[data-rrule-part]")) {
          return;
        }

        this.render();
      });
    }

    render() {
      this.toggleSections();
      this.output.value = this.buildRrule();
    }

    toggleSections() {
      const isWeekly = this.fields.freq.value === "WEEKLY";
      const isMonthly = this.fields.freq.value === "MONTHLY";
      const isYearly = this.fields.freq.value === "YEARLY";
      const isMonthlyMonthday = isMonthly && this.fields.monthlyMode.value === "monthday";
      const isMonthlyWeekdaypos = isMonthly && this.fields.monthlyMode.value === "weekdaypos";
      const hasCountEnd = this.fields.endMode.value === "count";
      const hasUntilEnd = this.fields.endMode.value === "until";

      this.setVisible(this.sections.weekly, isWeekly);
      this.setVisible(this.sections.monthly, isMonthly);
      this.setVisible(this.sections.yearly, isYearly);
      this.setVisible(this.sections.monthlyMonthday, isMonthlyMonthday);
      this.sections.monthlyWeekdaypos.forEach((section) => {
        this.setVisible(section, isMonthlyWeekdaypos);
      });
      this.setVisible(this.sections.endCount, hasCountEnd);
      this.setVisible(this.sections.endUntil, hasUntilEnd);
    }

    buildRrule() {
      const segments = [];
      const interval = this.clampInt(this.fields.interval.value, 1, 999, 1);

      this.fields.interval.value = String(interval);
      segments.push(`FREQ=${this.fields.freq.value}`);
      segments.push(`INTERVAL=${interval}`);

      if (this.fields.freq.value === "WEEKLY") {
        const selectedDays = this.fields.byday.filter((item) => item.checked).map((item) => item.value);

        if (selectedDays.length > 0) {
          segments.push(`BYDAY=${selectedDays.join(",")}`);
        }
      }

      if (this.fields.freq.value === "MONTHLY") {
        if (this.fields.monthlyMode.value === "monthday") {
          const bymonthday = this.clampInt(this.fields.bymonthday.value, 1, 31, 1);
          this.fields.bymonthday.value = String(bymonthday);
          segments.push(`BYMONTHDAY=${bymonthday}`);
        } else {
          segments.push(`BYSETPOS=${this.fields.bysetpos.value}`);
          segments.push(`BYDAY=${this.fields.monthlyWeekday.value}`);
        }
      }

      if (this.fields.freq.value === "YEARLY") {
        const bymonth = this.clampInt(this.fields.bymonth.value, 1, 12, 1);
        const bymonthday = this.clampInt(this.fields.yearlyBymonthday.value, 1, 31, 1);
        this.fields.bymonth.value = String(bymonth);
        this.fields.yearlyBymonthday.value = String(bymonthday);

        segments.push(`BYMONTH=${bymonth}`);
        segments.push(`BYMONTHDAY=${bymonthday}`);
      }

      if (this.fields.endMode.value === "count") {
        const count = this.clampInt(this.fields.count.value, 1, 99999, 1);
        this.fields.count.value = String(count);
        segments.push(`COUNT=${count}`);
      }

      if (this.fields.endMode.value === "until") {
        const untilToken = this.toUntilToken(this.fields.until.value);
        if (untilToken) {
          segments.push(`UNTIL=${untilToken}`);
        }
      }

      return segments.join(";");
    }

    parseInitialValue() {
      const raw = this.normalizeRrule(this.output.value);
      if (!raw) {
        return;
      }

      const parts = this.parseRrule(raw);
      const freq = (parts.FREQ ?? "").toUpperCase();

      if (["DAILY", "WEEKLY", "MONTHLY", "YEARLY"].includes(freq)) {
        this.fields.freq.value = freq;
      }

      if (parts.INTERVAL) {
        this.fields.interval.value = String(this.clampInt(parts.INTERVAL, 1, 999, 1));
      }

      if (parts.BYDAY && this.fields.freq.value === "WEEKLY") {
        const weeklyDays = this.parseByDayList(parts.BYDAY);
        this.fields.byday.forEach((item) => {
          item.checked = weeklyDays.includes(item.value);
        });
      }

      if (this.fields.freq.value === "MONTHLY") {
        const byDayTokens = this.parseByDayTokens(parts.BYDAY ?? "");
        const firstByDayToken = byDayTokens[0] ?? null;

        if (parts.BYMONTHDAY) {
          this.fields.monthlyMode.value = "monthday";
          this.fields.bymonthday.value = String(this.clampInt(parts.BYMONTHDAY, 1, 31, 1));
        } else if (parts.BYSETPOS && firstByDayToken?.weekday) {
          this.fields.monthlyMode.value = "weekdaypos";
          this.fields.bysetpos.value = String(this.clampInt(parts.BYSETPOS, -53, 53, 1));
          this.fields.monthlyWeekday.value = firstByDayToken.weekday;
        } else if (firstByDayToken?.position && firstByDayToken.weekday) {
          this.fields.monthlyMode.value = "weekdaypos";
          this.fields.bysetpos.value = String(this.clampInt(firstByDayToken.position, -53, 53, 1));
          this.fields.monthlyWeekday.value = firstByDayToken.weekday;
        }
      }

      if (this.fields.freq.value === "YEARLY") {
        if (parts.BYMONTH) {
          this.fields.bymonth.value = String(this.clampInt(parts.BYMONTH, 1, 12, 1));
        }

        if (parts.BYMONTHDAY) {
          this.fields.yearlyBymonthday.value = String(this.clampInt(parts.BYMONTHDAY, 1, 31, 1));
        }
      }

      if (parts.COUNT) {
        this.fields.endMode.value = "count";
        this.fields.count.value = String(this.clampInt(parts.COUNT, 1, 99999, 1));
      } else if (parts.UNTIL) {
        this.fields.endMode.value = "until";
        this.fields.until.value = this.toUntilValue(parts.UNTIL);
      }
    }

    /**
     * @param {string} value
     * @returns {Record<string, string>}
     */
    parseRrule(value) {
      return value
        .split(";")
        .map((token) => token.trim())
        .filter(Boolean)
        .reduce((accumulator, token) => {
          const [key, rawValue] = token.split("=", 2);
          if (!key || typeof rawValue === "undefined") {
            return accumulator;
          }

          accumulator[key.toUpperCase()] = rawValue.trim();
          return accumulator;
        }, {});
    }

    /**
     * @param {string} value
     */
    normalizeRrule(value) {
      const trimmed = value.trim();
      if (trimmed.toUpperCase().startsWith("RRULE:")) {
        return trimmed.slice(6).trim();
      }

      return trimmed;
    }

    /**
     * @param {string} byday
     * @returns {string[]}
     */
    parseByDayList(byday) {
      return this.parseByDayTokens(byday)
        .map((token) => token.weekday)
        .filter(Boolean);
    }

    /**
     * @param {string} byday
     * @returns {{ position: string|null, weekday: string|null }[]}
     */
    parseByDayTokens(byday) {
      return byday
        .split(",")
        .map((token) => token.trim().toUpperCase())
        .filter(Boolean)
        .map((token) => {
          const match = token.match(/^([+-]?\d{1,2})?([A-Z]{2})$/);
          if (!match) {
            return { position: null, weekday: null };
          }

          return {
            position: match[1] ?? null,
            weekday: match[2] ?? null,
          };
        });
    }

    /**
     * @param {string} value
     */
    toUntilValue(value) {
      const match = value.match(/^(\d{8})/);
      if (!match) {
        return "";
      }

      const date = match[1];
      return `${date.slice(0, 4)}-${date.slice(4, 6)}-${date.slice(6, 8)}`;
    }

    /**
     * @param {string} value
     */
    toUntilToken(value) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return "";
      }

      return `${value.replace(/-/g, "")}T235959Z`;
    }

    /**
     * @param {string} value
     * @param {number} min
     * @param {number} max
     * @param {number} fallback
     */
    clampInt(value, min, max, fallback) {
      const number = Number.parseInt(value, 10);
      if (Number.isNaN(number)) {
        return fallback;
      }

      return Math.min(max, Math.max(min, number));
    }

    /**
     * @param {Element | null} element
     * @param {boolean} visible
     */
    setVisible(element, visible) {
      if (!element) {
        return;
      }

      element.classList.toggle("is-hidden", !visible);
    }
  }

  const selector = "[data-rrule-builder]";

  const init = (context = document) => {
    context.querySelectorAll(selector).forEach((element) => {
      if (!(element instanceof HTMLElement) || element.dataset.rruleInit === "1") {
        return;
      }

      element.dataset.rruleInit = "1";
      new RruleBuilder(element);
    });
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => init());
  } else {
    init();
  }

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) {
          return;
        }

        if (node.matches(selector)) {
          init(node.parentElement ?? document);
          return;
        }

        init(node);
      });
    });
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
