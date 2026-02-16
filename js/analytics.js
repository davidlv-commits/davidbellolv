(function () {
  "use strict";

  var config = window.TDQV_ANALYTICS_CONFIG || {};
  var state = {
    enabled: false,
    gaLoaded: false,
    clarityLoaded: false,
    pageViewSent: false,
    engagementSent: false,
    scrollMilestones: {}
  };

  function hasValue(value) {
    return typeof value === "string" && value.trim().length > 0;
  }

  function getGaId() {
    return config.ga4MeasurementId || "";
  }

  function getClarityId() {
    return config.clarityProjectId || "";
  }

  function gtagAvailable() {
    return typeof window.gtag === "function";
  }

  function loadGa() {
    var gaId = getGaId();
    if (state.gaLoaded || !hasValue(gaId)) return;

    var script = document.createElement("script");
    script.async = true;
    script.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(gaId);
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer || [];
    window.gtag = function () {
      window.dataLayer.push(arguments);
    };

    window.gtag("js", new Date());
    window.gtag("config", gaId, {
      anonymize_ip: true,
      transport_type: "beacon"
    });

    state.gaLoaded = true;
  }

  function loadClarity() {
    var clarityId = getClarityId();
    if (state.clarityLoaded || !hasValue(clarityId)) return;

    (function (c, l, a, r, i, t, y) {
      c[a] = c[a] || function () {
        (c[a].q = c[a].q || []).push(arguments);
      };
      t = l.createElement(r);
      t.async = 1;
      t.src = "https://www.clarity.ms/tag/" + i;
      y = l.getElementsByTagName(r)[0];
      y.parentNode.insertBefore(t, y);
    })(window, document, "clarity", "script", clarityId);

    state.clarityLoaded = true;
  }

  function track(eventName, params) {
    if (!state.enabled || !gtagAvailable()) return;
    window.gtag("event", eventName, params || {});
  }

  function trackPageView(meta) {
    if (!state.enabled || state.pageViewSent) return;
    var payload = {
      page_path: window.location.pathname,
      page_title: document.title
    };
    if (meta && meta.language) payload.page_language = meta.language;
    track("page_view", payload);
    state.pageViewSent = true;
  }

  function bindClickTracking() {
    document.addEventListener("click", function (event) {
      var target = event.target.closest("[data-track]");
      if (!target) return;

      var label = target.getAttribute("data-track") || "unknown_click";
      var platform = target.getAttribute("data-platform") || "";
      var href = target.getAttribute("href") || "";

      track("cta_click", {
        cta_name: label,
        platform: platform,
        link_url: href,
        page_path: window.location.pathname
      });
    });
  }

  function bindScrollTracking() {
    var milestones = [25, 50, 75, 90];

    window.addEventListener(
      "scroll",
      function () {
        var doc = document.documentElement;
        var scrollTop = window.scrollY || doc.scrollTop || 0;
        var height = Math.max(doc.scrollHeight - window.innerHeight, 1);
        var progress = Math.round((scrollTop / height) * 100);

        milestones.forEach(function (milestone) {
          if (progress >= milestone && !state.scrollMilestones[milestone]) {
            state.scrollMilestones[milestone] = true;
            track("scroll_depth", {
              percent: milestone,
              page_path: window.location.pathname
            });
          }
        });
      },
      { passive: true }
    );
  }

  function bindMediaTracking() {
    document.querySelectorAll("video").forEach(function (video, index) {
      var mediaId = video.getAttribute("id") || "video_" + (index + 1);

      video.addEventListener("play", function () {
        track("video_play", { media_id: mediaId });
      });

      video.addEventListener("ended", function () {
        track("video_complete", { media_id: mediaId });
      });
    });
  }

  function bindEngagementTracking() {
    var startedAt = Date.now();

    function sendEngagedTime() {
      if (state.engagementSent) return;
      var seconds = Math.round((Date.now() - startedAt) / 1000);
      if (seconds < 20) return;

      state.engagementSent = true;
      track("engaged_time", {
        seconds: seconds,
        page_path: window.location.pathname
      });
    }

    window.addEventListener("beforeunload", sendEngagedTime);
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "hidden") sendEngagedTime();
    });
  }

  function bindInteractions() {
    if (state.bound) return;
    state.bound = true;
    bindClickTracking();
    bindScrollTracking();
    bindMediaTracking();
    bindEngagementTracking();
  }

  function applyConsent(consentLevel, meta) {
    bindInteractions();

    var allow = consentLevel === "all" || consentLevel === "accepted";
    if (!allow) return;

    if (!state.enabled) {
      loadGa();
      loadClarity();
      state.enabled = true;
    }

    trackPageView(meta || {});
  }

  window.TDQVAnalytics = {
    applyConsent: applyConsent,
    track: track,
    bindInteractions: bindInteractions
  };
})();
