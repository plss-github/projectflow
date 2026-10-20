(() => {
  "use strict";
  const $ = window.jQuery;
  if (!$) return;

  const csrfFor = ($el) => String($el.data("csrf") || "");
  const api = (url, data) =>
    $.ajax({ url, method: "POST", dataType: "json", data });
  const upload = (url, data) =>
    $.ajax({
      url,
      method: "POST",
      dataType: "json",
      data,
      processData: false,
      contentType: false,
    });

  function toast(text, type = "success") {
    let $stack = $(".pf-toast-stack");
    if (!$stack.length)
      $stack = $(
        '<div class="pf-toast-stack" aria-live="polite"></div>',
      ).appendTo("body");
    const icon =
      type === "error"
        ? "ti-alert-triangle"
        : type === "info"
          ? "ti-info-circle"
          : "ti-circle-check";
    const $t = $(
      `<div class="pf-toast is-${type}"><i class="ti ${icon}"></i><div></div></div>`,
    );
    $t.find("div").text(text || "Operação concluída.");
    $stack.append($t);
    setTimeout(() => $t.fadeOut(200, () => $t.remove()), 3800);
  }
  function busy($b, state) {
    if ($b?.length)
      $b.prop("disabled", !!state).toggleClass("pf-loading", !!state);
  }
  function formDataObject($form, checkboxNames = []) {
    const out = {};
    $form.serializeArray().forEach(({ name, value }) => {
      out[name] = value;
    });
    checkboxNames.forEach((n) => {
      out[n] = $form.find(`[name="${n}"]`).is(":checked") ? "1" : "0";
    });
    return out;
  }
  function reloadHash(hash) {
    if (hash) window.location.hash = hash;
    window.location.reload();
  }
  function setMessage($form, text, error = false) {
    let $m = $form.find(".pf-form-message");
    if (!$m.length)
      $m = $('<div class="pf-form-message"></div>').appendTo($form);
    $m.toggleClass("is-error", error)
      .toggleClass("is-success", !error)
      .text(text || "");
  }

  function initFavorite($scope, endpoint, csrf) {
    $scope.on("click", ".pf-favorite", function (e) {
      e.preventDefault();
      const $b = $(this),
        id = Number($b.data("project-id"));
      if (!id) return;
      busy($b, true);
      api(endpoint, { action: "favorite", id, _glpi_csrf_token: csrf })
        .done((r) => {
          if (!r?.ok)
            return toast(r?.message || "Falha ao favoritar.", "error");
          const active = !!r.favorite;
          $b.toggleClass("is-active", active)
            .find("i")
            .attr("class", `ti ti-star${active ? "-filled" : ""}`);
          $b.closest("[data-project-id]").attr(
            "data-favorite",
            active ? "1" : "0",
          );
        })
        .fail((x) =>
          toast(x.responseJSON?.message || "Falha ao favoritar.", "error"),
        )
        .always(() => busy($b, false));
    });
  }

  function initDashboard() {
    const $app = $("#projectflow-dashboard");
    if (!$app.length) return;
    const endpoint = String($app.data("project-endpoint")),
      csrf = csrfFor($app);
    let quick = "all";
    initFavorite($app, endpoint, csrf);
    const filter = () => {
      const q = String($("#pf-project-search").val() || "").toLowerCase(),
        state = String($("#pf-project-state-filter").val() || ""),
        health = String($("#pf-project-health-filter").val() || ""),
        portfolio = String($("#pf-project-portfolio-filter").val() || "");
      let n = 0;
      $(".pf-project-card").each(function () {
        const $c = $(this);
        let hit = !q || String($c.data("search") || "").includes(q);
        hit =
          hit &&
          (!state || String($c.data("state")) === state) &&
          (!health || String($c.data("health")) === health) &&
          (!portfolio || String($c.data("portfolio")) === portfolio);
        if (quick === "active")
          hit = hit && String($c.data("finished")) !== "1";
        if (quick === "overdue")
          hit = hit && String($c.data("overdue")) === "1";
        if (quick === "attention")
          hit =
            hit &&
            ["attention", "critical"].includes(String($c.data("health")));
        if (quick === "favorites")
          hit = hit && String($c.data("favorite")) === "1";
        $c.toggleClass("is-filtered", !hit);
        $(`[data-pf-table-project="${$c.data("project-id")}"]`).toggleClass(
          "is-filtered",
          !hit,
        );
        if (hit) n++;
      });
      $("#pf-visible-count").text(n);
    };
    $(
      "#pf-project-search,#pf-project-state-filter,#pf-project-health-filter,#pf-project-portfolio-filter",
    ).on("input change", filter);
    $("[data-pf-quick]").on("click", function () {
      quick = String($(this).data("pf-quick") || "all");
      $("[data-pf-quick]").removeClass("is-selected");
      $(this).addClass("is-selected");
      filter();
    });
    $("#pf-clear-project-filters").on("click", () => {
      quick = "all";
      $("#pf-project-search").val("");
      $(
        "#pf-project-state-filter,#pf-project-health-filter,#pf-project-portfolio-filter",
      ).val("");
      filter();
    });
    const syncCreateAccounting = () => {
      const mode = String(
        $('#pf-project-create-form [name="cost_mode"]:checked').val() ||
          "hours",
      );
      $("#pf-project-create-form .pf-hours-budget-wrap").toggle(
        mode !== "money",
      );
      $("#pf-project-create-form .pf-hour-rate-wrap").toggle(mode === "both");
      $("#pf-project-create-form .pf-accounting-card")
        .removeClass("is-selected")
        .has("input:checked")
        .addClass("is-selected");
    };
    $('#pf-project-create-form [name="cost_mode"]').on(
      "change",
      syncCreateAccounting,
    );
    syncCreateAccounting();
    const pkCounts = () => {
      $(".pf-pk-col").each(function () {
        const n = $(this).find(".pf-pk-card:not(.is-filtered)").length;
        $(this).find(".pf-pk-count").text(n);
        $(this).toggleClass("is-empty", n === 0);
      });
    };
    const setView = (v) => {
      if (!["cards", "table", "kanban"].includes(v)) v = "cards";
      $("#pf-project-grid").toggleClass("d-none", v !== "cards");
      $("#pf-project-table-view").toggleClass("d-none", v !== "table");
      $("#pf-project-kanban-view").toggleClass("d-none", v !== "kanban");
      $(".pf-view-switch button")
        .removeClass("is-active")
        .filter(`[data-view="${v}"]`)
        .addClass("is-active");
      try {
        localStorage.setItem("projectflow.dashboard.view", v);
      } catch (e) {}
      pkCounts();
    };
    $(".pf-view-switch button").on("click", function () {
      setView(String($(this).data("view")));
    });
    const pfQs = new URLSearchParams(window.location.search);
    let pfStoredView = null;
    try {
      pfStoredView = localStorage.getItem("projectflow.dashboard.view");
    } catch (e) {}
    setView(
      pfQs.get("view") ||
        pfStoredView ||
        String($app.data("default-view") || "cards"),
    );
    $(
      "#pf-project-search,#pf-project-state-filter,#pf-project-health-filter,#pf-project-portfolio-filter",
    ).on("input change", () => setTimeout(pkCounts, 0));
    $("[data-pf-quick],#pf-clear-project-filters").on("click", () =>
      setTimeout(pkCounts, 0),
    );
    // Kanban de projetos: arrastar um cartão para outra coluna muda o estado do projeto.
    (function () {
      let dragId = 0,
        $from = null;
      $app.on("dragstart", ".pf-pk-card.is-draggable", function (e) {
        dragId = Number($(this).data("pf-pk-project"));
        $from = $(this).closest(".pf-pk-col");
        $(this).addClass("is-dragging");
        e.originalEvent.dataTransfer.effectAllowed = "move";
        e.originalEvent.dataTransfer.setData("text/plain", String(dragId));
      });
      $app.on("dragend", ".pf-pk-card", function () {
        $(this).removeClass("is-dragging");
        $(".pf-pk-col").removeClass("is-over");
      });
      $app.on("dragover", ".pf-pk-col", function (e) {
        if (!dragId) return;
        e.preventDefault();
        $(".pf-pk-col").not(this).removeClass("is-over");
        $(this).addClass("is-over");
      });
      $app.on("drop", ".pf-pk-col", function (e) {
        e.preventDefault();
        const $col = $(this),
          state = Number($col.data("pf-pk-state")),
          $card = $(`.pf-pk-card[data-pf-pk-project="${dragId}"]`);
        $col.removeClass("is-over");
        if (!dragId || !$card.length || $col.is($from)) {
          dragId = 0;
          return;
        }
        const $origin = $from;
        $col.find(".pf-pk-list").prepend($card);
        pkCounts();
        api(endpoint, {
          action: "update",
          id: dragId,
          projectstates_id: state,
          _glpi_csrf_token: csrf,
        })
          .done((r) => {
            if (!r?.ok) {
              $origin.find(".pf-pk-list").prepend($card);
              pkCounts();
              return toast(
                r?.message || "Não foi possível mudar o estado.",
                "error",
              );
            }
            toast("Estado do projeto atualizado.");
          })
          .fail((x) => {
            $origin.find(".pf-pk-list").prepend($card);
            pkCounts();
            toast(
              x.responseJSON?.message || "Não foi possível mudar o estado.",
              "error",
            );
          });
        dragId = 0;
      });
    })();
    if (pfQs.get("new") === "1") {
      const m = document.getElementById("pf-project-create-modal");
      if (m && window.bootstrap?.Modal) {
        $('#pf-project-create-form [name="template_id"]')
          .val("0")
          .trigger("change");
        window.bootstrap.Modal.getOrCreateInstance(m).show();
      }
      const u = new URL(window.location.href);
      u.searchParams.delete("new");
      history.replaceState(null, "", u.toString());
    }
    $app.on("click", "[data-pf-new-project]", function () {
      const tpl = String($(this).data("pf-new-project") || "0");
      $('#pf-project-create-form [name="template_id"]')
        .val(tpl)
        .trigger("change");
      const m = document.getElementById("pf-project-create-modal");
      if (m && !this.hasAttribute("data-bs-toggle") && window.bootstrap?.Modal)
        window.bootstrap.Modal.getOrCreateInstance(m).show();
    });
    // Template = autocomplete: selecting a template fills every tab with its definitions and lists its tasks.
    const $cf = $("#pf-project-create-form");
    let tplReq = 0;
    const esc = (v) =>
      $("<div>")
        .text(String(v ?? ""))
        .html();
    const syncTplTasks = () => {
      const $c = $("#pf-tpl-task-list .pf-tpl-task-check");
      const ids = $c
        .filter(":checked")
        .map(function () {
          return String($(this).val());
        })
        .get();
      $cf.find('[name="template_task_ids"]').val(ids.join(","));
      $("#pf-tpl-tasks-count").text(
        `${ids.length} de ${$c.length} tarefa(s) serão criadas`,
      );
      $("#pf-tpl-task-badge")
        .text(`${ids.length}/${$c.length}`)
        .toggleClass("d-none", !$c.length);
      $("#pf-tpl-task-list .pf-tpl-task").each(function () {
        $(this).toggleClass(
          "is-off",
          !$(this).find(".pf-tpl-task-check").is(":checked"),
        );
      });
    };
    const setField = (name, value) => {
      const $el = $cf.find(`[name="${name}"]`);
      if (!$el.length) return;
      if ($el.is(":radio")) {
        $el
          .prop("checked", false)
          .filter(`[value="${value}"]`)
          .prop("checked", true);
        return;
      }
      if ($el.is(":checkbox")) {
        $el.prop("checked", !!value);
        return;
      }
      if ($el.is("select")) {
        const v = String(value ?? "");
        if ($el.find(`option[value="${v}"]`).length) $el.val(v);
        return;
      }
      $el.val(String(value ?? ""));
    };
    const clearTemplate = () => {
      $("#pf-template-applied").addClass("d-none");
      $("#pf-tpl-tasks").addClass("d-none");
      $("#pf-tpl-tasks-empty").removeClass("d-none");
      $("#pf-tpl-task-list").empty();
      $cf.find('[name="template_task_ids"]').val("");
      $cf.find('[name="template_tasks_filtered"]').val("");
      $("#pf-tpl-task-badge").addClass("d-none");
    };
    const renderTplTasks = (tasks) => {
      const byParent = {};
      tasks.forEach((t) => {
        (byParent[t.parent_id] || (byParent[t.parent_id] = [])).push(t);
      });
      const ids = new Set(tasks.map((t) => t.id));
      const out = [];
      const walk = (pid, level) => {
        (byParent[pid] || []).forEach((t) => {
          out.push({ ...t, level });
          walk(t.id, level + 1);
        });
      };
      walk(0, 0);
      tasks.forEach((t) => {
        if (
          t.parent_id &&
          !ids.has(t.parent_id) &&
          !out.find((o) => o.id === t.id)
        )
          out.push({ ...t, level: 0 });
      });
      $("#pf-tpl-task-list").html(
        out
          .map(
            (t) =>
              `<label class="pf-tpl-task" style="--pf-level:${Math.min(t.level, 4)}"><input type="checkbox" class="form-check-input pf-tpl-task-check" value="${t.id}" checked><span class="pf-tpl-task-name">${t.level ? '<i class="ti ti-corner-down-right"></i>' : ""}${esc(t.name)}</span><span class="pf-tpl-task-meta">${t.is_milestone ? '<span class="badge bg-azure-lt">Marco</span>' : ""}${t.type_name ? `<span>${esc(t.type_name)}</span>` : ""}${t.planned_hours ? `<span><i class="ti ti-clock"></i> ${String(t.planned_hours).replace(".", ",")}h</span>` : ""}</span></label>`,
          )
          .join(""),
      );
      const has = out.length > 0;
      $("#pf-tpl-tasks").toggleClass("d-none", !has);
      $("#pf-tpl-tasks-empty").toggleClass("d-none", has);
      if (!has)
        $("#pf-tpl-tasks-empty")
          .find("strong")
          .text("Template sem tarefas")
          .end()
          .find("span")
          .text(
            "Este template não tem tarefas cadastradas. Você cria as tarefas depois, dentro do projeto.",
          );
      else
        $("#pf-tpl-tasks-empty")
          .find("strong")
          .text("Projeto em branco")
          .end()
          .find("span")
          .text(
            "Escolha um template na aba Identificação para trazer as tarefas dele. Sem template, você cria as tarefas depois, dentro do projeto.",
          );
      $cf.find('[name="template_tasks_filtered"]').val(has ? "1" : "");
      syncTplTasks();
    };
    $cf.on("change", ".pf-tpl-task-check", syncTplTasks);
    $cf.on("click", "[data-pf-tpl-check]", function () {
      $("#pf-tpl-task-list .pf-tpl-task-check").prop(
        "checked",
        $(this).data("pf-tpl-check") === "all",
      );
      syncTplTasks();
    });
    $cf.on("click", "[data-pf-goto-form-tab]", function (e) {
      e.preventDefault();
      $cf
        .find(`[data-pf-form-tab="${$(this).data("pf-goto-form-tab")}"]`)
        .trigger("click");
    });
    $cf.find('[name="template_id"]').on("change", function () {
      const id = Number($(this).val() || 0),
        req = ++tplReq;
      if (id <= 0) {
        clearTemplate();
        return;
      }
      const $sel = $(this);
      $sel.prop("disabled", true);
      api(endpoint, {
        action: "template_info",
        template_id: id,
        _glpi_csrf_token: csrf,
      })
        .done((r) => {
          if (req !== tplReq) return;
          if (!r?.ok || !r.template) {
            toast(
              r?.message || "Não foi possível carregar o template.",
              "error",
            );
            return;
          }
          const tp = r.template;
          Object.entries(tp.fields || {}).forEach(([k, v]) => setField(k, v));
          $cf.find('[name="cost_mode"]').trigger("change");
          $("#pf-template-applied")
            .removeClass("d-none")
            .find("[data-pf-tpl-name]")
            .text(tp.name || "");
          renderTplTasks(tp.tasks || []);
        })
        .fail((x) => {
          if (req === tplReq)
            toast(
              x.responseJSON?.message ||
                "Não foi possível carregar o template.",
              "error",
            );
        })
        .always(() => {
          if (req === tplReq) $sel.prop("disabled", false);
        });
    });
    // Coming from the templates screen ("Criar projeto"): open the form with that template applied.
    (function () {
      const params = new URLSearchParams(window.location.search),
        tpl = Number(params.get("template") || 0);
      if (tpl <= 0) return;
      const $sel = $cf.find('[name="template_id"]');
      if (!$sel.find(`option[value="${tpl}"]`).length) return;
      $sel.val(String(tpl)).trigger("change");
      const m = document.getElementById("pf-project-create-modal");
      if (m && window.bootstrap?.Modal)
        window.bootstrap.Modal.getOrCreateInstance(m).show();
      try {
        params.delete("template");
        const u = new URL(window.location.href);
        u.search = params.toString();
        window.history.replaceState(null, "", u.toString());
      } catch (e) {}
    })();
    $("#pf-project-create-modal").on("hidden.bs.modal", function () {
      $cf[0].reset();
      clearTemplate();
      syncCreateAccounting();
    });
    $("#pf-project-create-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"]');
      busy($b, true);
      api(endpoint, formDataObject($f, ["auto_percent_done", "is_recursive"]))
        .done((r) => {
          if (!r?.ok) {
            setMessage(
              $f,
              r?.message || "Não foi possível criar o projeto.",
              true,
            );
            return;
          }
          window.location.href = r.url;
        })
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Erro ao criar projeto.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
  }

  function initTemplates() {
    const $app = $("#projectflow-templates");
    if (!$app.length) return;
    const renumberRoles = () => {
      $("#pf-tpl-role-rows .pf-tpl-role-row").each(function (i) {
        $(this).find("input").eq(0).attr("name", `roles[${i}][name]`);
        $(this).find("input").eq(1).attr("name", `roles[${i}][color]`);
      });
    };
    $("#pf-tpl-role-add").on("click", function () {
      const palette = [
        "#4263eb",
        "#2fb344",
        "#f59f00",
        "#d6336c",
        "#7048e8",
        "#0ca678",
        "#e8590c",
      ];
      const n = $("#pf-tpl-role-rows .pf-tpl-role-row").length;
      $("#pf-tpl-role-rows").append(
        `<div class="pf-tpl-role-row"><input class="form-control" maxlength="120" placeholder="Nome da função"><input type="color" class="form-control form-control-color" value="${palette[n % palette.length]}" title="Cor"><button type="button" class="btn btn-sm btn-ghost-danger pf-icon-btn pf-tpl-role-remove" title="Remover"><i class="ti ti-trash"></i></button></div>`,
      );
      renumberRoles();
      $("#pf-tpl-role-rows .pf-tpl-role-row")
        .last()
        .find("input")
        .first()
        .trigger("focus");
    });
    $(document).on("click", ".pf-tpl-role-remove", function () {
      $(this).closest(".pf-tpl-role-row").remove();
      renumberRoles();
    });
    $("#pf-tpl-search").on("input", function () {
      const q = String($(this).val() || "")
        .toLowerCase()
        .trim();
      let n = 0;
      $("#pf-tpl-grid .pf-tpl-card[data-search]").each(function () {
        const hit = !q || String($(this).data("search") || "").includes(q);
        $(this).toggleClass("d-none", !hit);
        if (hit) n++;
      });
      $("#pf-tpl-visible").text(n);
      $("#pf-tpl-noresult").toggleClass("d-none", n > 0);
      $(".pf-tpl-card-new").toggleClass("d-none", !!q);
    });
    if (new URLSearchParams(window.location.search).get("new") === "1") {
      const m = document.getElementById("pf-template-new-modal");
      if (m && window.bootstrap?.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(m).show();
      }
      try {
        const u = new URL(window.location.href);
        u.searchParams.delete("new");
        window.history.replaceState(null, "", u.toString());
      } catch (e) {}
    }
    const endpoint = String($app.data("project-endpoint"));
    $(".pf-use-template").on("click", function () {
      const $m = $("#pf-template-create-modal");
      $("#pf-template-modal-title").text(
        `Novo projeto · ${$(this).data("template-name") || "Template"}`,
      );
      $('#pf-template-create-form [name="template_id"]').val(
        $(this).data("template-id"),
      );
      window.bootstrap?.Modal?.getOrCreateInstance($m[0]).show();
    });
    const renumberTplTasks = () => {
      const $rows = $("#pf-template-task-rows .pf-template-task-row");
      $rows.each(function (i) {
        $(this)
          .find("[data-field]")
          .each(function () {
            $(this).attr("name", `tasks[${i}][${$(this).data("field")}]`);
          });
      });
      $("#pf-template-task-empty").toggleClass("d-none", $rows.length > 0);
    };
    const addTplTask = () => {
      const tpl = document.getElementById("pf-template-task-row");
      if (!tpl) return;
      $("#pf-template-task-rows").append(tpl.content.cloneNode(true));
      renumberTplTasks();
      $("#pf-template-task-rows .pf-template-task-row")
        .last()
        .find('[data-field="name"]')
        .trigger("focus");
    };
    $("#pf-template-task-add").on("click", addTplTask);
    $("#pf-template-task-rows").on(
      "click",
      ".pf-template-task-remove",
      function () {
        $(this).closest(".pf-template-task-row").remove();
        renumberTplTasks();
      },
    );
    $("#pf-template-task-rows").on(
      "keydown",
      '[data-field="name"]',
      function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          addTplTask();
        }
      },
    );
    const syncTplAccounting = () => {
      const $f = $("#pf-template-new-form");
      const mode = String(
        $f.find('[name="cost_mode"]:checked').val() || "hours",
      );
      $f.find(".pf-hours-budget-wrap").toggle(mode !== "money");
      $f.find(".pf-hour-rate-wrap").toggle(mode === "both");
      $f.find(".pf-accounting-card")
        .removeClass("is-selected")
        .has("input:checked")
        .addClass("is-selected");
    };
    $('#pf-template-new-form [name="cost_mode"]').on(
      "change",
      syncTplAccounting,
    );
    syncTplAccounting();
    if (
      document.getElementById("pf-template-task-row") &&
      !$("#pf-template-task-rows .pf-template-task-row").length
    )
      addTplTask();
    $("#pf-template-new-form").on("submit", function (e) {
      e.preventDefault();
      renumberTplTasks();
      const $f = $(this),
        $b = $f.find('button[type="submit"]');
      busy($b, true);
      api(endpoint, $f.serialize())
        .done((r) =>
          r?.ok
            ? (window.location.href = r.url)
            : setMessage($f, r?.message || "Falha ao criar template.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao criar template.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $("#pf-template-create-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"]');
      busy($b, true);
      api(endpoint, $f.serialize())
        .done((r) =>
          r?.ok
            ? (window.location.href = r.url)
            : setMessage($f, r?.message || "Falha ao criar projeto.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao criar projeto.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
  }

  function initMemberSelector($scope) {
    $scope.find(".pf-member-type").each(function () {
      const $type = $(this),
        $select = $type.closest("form").find(".pf-member-select");
      if (!$select.length) return;
      const refresh = () => {
        const type = String($type.val() || "User");
        $select.find("option").each(function () {
          const k = String($(this).data("kind") || "User");
          $(this).prop("hidden", k !== type);
        });
        const $visible = $select.find("option:not([hidden])").first();
        if (!$select.find("option:selected:not([hidden])").length)
          $select.val($visible.val());
      };
      $type.on("change", refresh);
      refresh();
    });
  }

  function initWorkspace() {
    const $app = $("#projectflow-workspace");
    if (!$app.length) return;
    const projectId = Number($app.data("project-id")),
      taskEndpoint = String($app.data("task-endpoint")),
      projectEndpoint = String($app.data("project-endpoint")),
      docEndpoint = String($app.data("document-endpoint")),
      csrf = csrfFor($app);
    initFavorite($app, projectEndpoint, csrf);
    initMemberSelector($app);
    const syncEditAccounting = () => {
      const mode = String(
        $('#pf-project-edit-form [name="cost_mode"]').val() || "hours",
      );
      $("#pf-project-edit-form .pf-hours-budget-wrap").toggle(mode !== "money");
      $("#pf-project-edit-form .pf-hour-rate-wrap").toggle(mode === "both");
    };
    $('#pf-project-edit-form [name="cost_mode"]').on(
      "change",
      syncEditAccounting,
    );
    syncEditAccounting();
    const activate = (name) => {
      name = name || "overview";
      const exists = $(`[data-pf-pane="${name}"]`).length;
      if (!exists) name = "overview";
      $("[data-pf-tab]")
        .removeClass("is-active")
        .filter(`[data-pf-tab="${name}"]`)
        .addClass("is-active");
      $("[data-pf-pane]")
        .removeClass("is-active")
        .filter(`[data-pf-pane="${name}"]`)
        .addClass("is-active");
      localStorage.setItem(`projectflow.project.${projectId}.tab`, name);
      if (name === "weekly") {
        const $t = $("#pf-weekly-report-text");
        if (
          $t.length &&
          $t.attr("data-pf-lazy") === "1" &&
          !String($t.val() || "").trim()
        ) {
          $t.attr("data-pf-lazy", "0");
          $("#pf-report-generate").trigger("click");
        }
      }
    };
    $("[data-pf-tab]").on("click", function () {
      const n = String($(this).data("pf-tab"));
      activate(n);
      history.replaceState(null, "", `#${n}`);
    });
    $("[data-pf-goto]").on("click", function () {
      const n = String($(this).data("pf-goto"));
      activate(n);
      history.replaceState(null, "", `#${n}`);
    });
    const initialTab =
      location.hash.slice(1) ||
      localStorage.getItem(`projectflow.project.${projectId}.tab`) ||
      "overview";

    $("#pf-project-edit-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"]');
      busy($b, true);
      api(projectEndpoint, {
        action: "update",
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? reloadHash(location.hash)
            : setMessage($f, r?.message || "Falha ao salvar.", true),
        )
        .fail((x) =>
          setMessage($f, x.responseJSON?.message || "Falha ao salvar.", true),
        )
        .always(() => busy($b, false));
    });
    // Nova tarefa: pai (raiz) ou filha (exige escolher a tarefa pai, só do próprio projeto).
    $("[data-pf-new-task]").on("click", function () {
      const child = $(this).data("pf-new-task") === "child",
        $f = $("#pf-task-create-form");
      $("#pf-task-create-title").text(
        child ? "Nova tarefa filha" : "Nova tarefa pai",
      );
      $f.find(".pf-parent-task-wrap").toggleClass("d-none", !child);
      const $sel = $f.find(".pf-parent-task-select");
      $sel.prop("required", child).val("0");
      if (child) $sel.find('option[value="0"]').prop("disabled", true);
      else $sel.find('option[value="0"]').prop("disabled", false);
    });
    $("#pf-task-create-modal").on("show.bs.modal", function (e) {
      if (!$(e.relatedTarget).is("[data-pf-new-task]")) {
        $("#pf-task-create-title").text("Nova tarefa");
        $(this).find(".pf-parent-task-wrap").addClass("d-none");
        $(this).find(".pf-parent-task-select").prop("required", false).val("0");
      }
    });
    $("#pf-task-create-form").on("submit", function (e) {
      if (
        !$(this).find(".pf-parent-task-wrap").hasClass("d-none") &&
        Number($(this).find(".pf-parent-task-select").val() || 0) <= 0
      ) {
        e.preventDefault();
        e.stopImmediatePropagation();
        setMessage($(this), "Escolha de quem esta tarefa é filha.", true);
        return;
      }
    });
    $("#pf-task-create-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"]');
      busy($b, true);
      api(taskEndpoint, {
        action: "create",
        _glpi_csrf_token: csrf,
        ...formDataObject($f, ["is_milestone", "attention"]),
      })
        .done((r) => {
          if (!r?.ok)
            return setMessage($f, r?.message || "Falha ao criar tarefa.", true);
          const u = new URL(window.location.href);
          u.searchParams.set("task", String(r.id));
          u.hash = "execution";
          window.location.href = u.toString();
        })
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao criar tarefa.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });

    let dragged = 0,
      origin = null;
    const allowedOf = ($c) =>
      String($c.attr("data-allowed-states") || "")
        .split(",")
        .filter(Boolean);
    $app.on("dragstart", '.pf-task-card[draggable="true"]', function (e) {
      dragged = Number($(this).data("task-id"));
      origin = $(this).parent();
      $(this).addClass("pf-dragging");
      const allowed = allowedOf($(this));
      if (allowed.length) {
        $(".pf-dropzone").each(function () {
          if (!allowed.includes(String($(this).data("state-id"))))
            $(this).closest(".pf-board-column").addClass("pf-drop-blocked");
        });
      }
      e.originalEvent.dataTransfer.setData("text/plain", String(dragged));
    });
    $app.on("dragend", ".pf-task-card", function () {
      $(this).removeClass("pf-dragging");
      $(".pf-board-column").removeClass("pf-drop-blocked");
      $(".pf-dropzone").removeClass("pf-drag-over");
      dragged = 0;
      origin = null;
    });
    $app.on("dragover", ".pf-dropzone", function (e) {
      e.preventDefault();
      $(this).addClass("pf-drag-over");
    });
    $app.on("dragleave", ".pf-dropzone", function () {
      $(this).removeClass("pf-drag-over");
    });
    $app.on("drop", ".pf-dropzone", function (e) {
      e.preventDefault();
      const id =
          dragged || Number(e.originalEvent.dataTransfer.getData("text/plain")),
        state = Number($(this).data("state-id")),
        $card = $(`.pf-task-card[data-task-id="${id}"]`),
        $target = $(this);
      if (!id || !state || $card.parent().is($target)) return;
      const allowed = allowedOf($card);
      if (allowed.length && !allowed.includes(String(state))) {
        $(".pf-board-column").removeClass("pf-drop-blocked");
        return toast(
          "Este estado não está liberado para esta tarefa.",
          "error",
        );
      }
      const $old = $card.parent();
      $target.append($card);
      api(taskEndpoint, {
        action: "move",
        id,
        projectstates_id: state,
        _glpi_csrf_token: csrf,
      })
        .done((r) => {
          if (!r?.ok) {
            $old.append($card);
            return toast(r?.message || "Falha ao mover tarefa.", "error");
          }
          reloadHash("#execution");
        })
        .fail((x) => {
          $old.append($card);
          toast(x.responseJSON?.message || "Falha ao mover tarefa.", "error");
        });
    });
    $("#pf-board-search").on("input", function () {
      const q = String($(this).val() || "").toLowerCase();
      $("[data-pf-tree]").toggleClass("is-searching", !!q);
      $(".pf-task-card, .pf-task-row").each(function () {
        $(this).toggle(!q || String($(this).data("search") || "").includes(q));
      });
    });
    (function () {
      const key = "pf.projectView";
      const apply = function (view) {
        if (view !== "list") {
          view = "kanban";
        }
        $("[data-pf-view]").each(function () {
          const on = $(this).data("pfView") === view;
          $(this)
            .toggleClass("active", on)
            .attr("aria-pressed", on ? "true" : "false");
        });
        $("[data-pf-view-pane]").each(function () {
          $(this).toggleClass("d-none", $(this).data("pfViewPane") !== view);
        });
      };
      let saved = "kanban";
      try {
        saved = window.localStorage.getItem(key) || "kanban";
      } catch (e) {}
      apply(saved);
      $(document).on("click", "[data-pf-view]", function () {
        const view = $(this).data("pfView");
        apply(view);
        try {
          window.localStorage.setItem(key, view);
        } catch (e) {}
      });
    })();

    $(".pf-team-add-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this);
      api(projectEndpoint, {
        action: "team_add",
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? reloadHash("#team")
            : toast(r?.message || "Falha ao adicionar membro.", "error"),
        )
        .fail((x) =>
          toast(
            x.responseJSON?.message || "Falha ao adicionar membro.",
            "error",
          ),
        );
    });
    $(".pf-role-select").on("change", function () {
      const $s = $(this);
      $s.prop("disabled", true);
      api(projectEndpoint, {
        action: "team_role_set",
        project_id: projectId,
        relation_id: Number($s.data("relation-id")),
        roles_id: Number($s.val() || 0),
        _glpi_csrf_token: csrf,
      })
        .done((r) => {
          if (r?.ok) {
            reloadHash("#team");
          } else toast(r?.message || "Falha ao definir função.", "error");
        })
        .fail((x) =>
          toast(x.responseJSON?.message || "Falha ao definir função.", "error"),
        )
        .always(() => $s.prop("disabled", false));
    });
    $("#pf-roles-modal").on("hidden.bs.modal", function () {
      if ($(this).data("changed")) reloadHash("#team");
    });
    $(".pf-role-edit").on("click", function () {
      let r = {};
      try {
        r = JSON.parse(String($(this).attr("data-role") || "{}"));
      } catch {}
      const $f = $(".pf-role-form");
      $f.find('[name="role_id"]').val(r.id || 0);
      $f.find('[name="name"]')
        .val(r.name || "")
        .trigger("focus");
      $f.find('[name="color"]').val(r.color || "#4263eb");
      $f.find('[name="comment"]').val(r.comment || "");
      $f.find(".pf-role-form-title").text("Editar função");
    });
    $(".pf-role-delete").on("click", function () {
      if (
        !confirm(
          `Excluir a função "${$(this).data("name")}"? Ela será removida de quem a tinha.`,
        )
      )
        return;
      api(projectEndpoint, {
        action: "role_delete",
        project_id: projectId,
        role_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) => {
        if (r?.ok) {
          $("#pf-roles-modal").data("changed", 1);
          $(this).closest(".pf-role-item").remove();
          toast("Função excluída.");
        } else toast(r?.message || "Falha ao excluir.", "error");
      });
    });
    $(".pf-role-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"]');
      busy($b, true);
      api(projectEndpoint, {
        action: "role_save",
        project_id: projectId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) => {
          if (!r?.ok)
            return setMessage(
              $f,
              r?.message || "Falha ao salvar função.",
              true,
            );
          $("#pf-roles-modal").data("changed", 1);
          reloadHash("#team");
        })
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao salvar função.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $(".pf-team-remove").on("click", function () {
      if (!confirm("Remover este membro da equipe?")) return;
      api(projectEndpoint, {
        action: "team_remove",
        project_id: projectId,
        relation_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? reloadHash("#team")
          : toast(r?.message || "Falha ao remover.", "error"),
      );
    });

    const resetMeetingForm = () => {
      const $f = $("#pf-meeting-form");
      if (!$f.length) return;
      $f[0].reset();
      $f.find('[name="project_id"]').val(projectId);
      $f.find('[name="meeting_id"]').val("0");
      $f.find('[name="duration_minutes"]').val("60");
      $("#pf-meeting-modal-title").text("Registrar reunião");
      $("#pf-meeting-submit").text("Registrar reunião");
      setMessage($f, "");
    };
    $(".pf-meeting-new").on("click", resetMeetingForm);
    $(".pf-meeting-edit").on("click", function () {
      const $b = $(this),
        $f = $("#pf-meeting-form");
      resetMeetingForm();
      $f.find('[name="meeting_id"]').val(String($b.data("id") || 0));
      $f.find('[name="title"]').val(String($b.attr("data-title") || ""));
      $f.find('[name="start_at"]').val(String($b.attr("data-start") || ""));
      $f.find('[name="duration_minutes"]').val(
        String($b.attr("data-duration") || 60),
      );
      $f.find('[name="participants"]').val(
        String($b.attr("data-participants") || ""),
      );
      $f.find('[name="summary"]').val(String($b.attr("data-summary") || ""));
      $f.find('[name="decisions"]').val(
        String($b.attr("data-decisions") || ""),
      );
      $f.find('[name="actions"]').val(String($b.attr("data-actions") || ""));
      $("#pf-meeting-modal-title").text("Editar reunião");
      $("#pf-meeting-submit").text("Salvar reunião");
    });
    $("#pf-meeting-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find("#pf-meeting-submit"),
        meetingId = Number($f.find('[name="meeting_id"]').val() || 0),
        action = meetingId > 0 ? "meeting_update" : "meeting_add";
      busy($b, true);
      api(projectEndpoint, {
        action,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? reloadHash("#meetings")
            : setMessage($f, r?.message || "Falha ao salvar reunião.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao salvar reunião.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $(".pf-meeting-delete").on("click", function () {
      if (!confirm("Remover esta reunião e suas horas?")) return;
      api(projectEndpoint, {
        action: "meeting_delete",
        project_id: projectId,
        meeting_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? reloadHash("#meetings")
          : toast(r?.message || "Falha ao remover reunião.", "error"),
      );
    });

    $(".pf-contract-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this);
      api(projectEndpoint, {
        action: "contract_link",
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      }).done((r) =>
        r?.ok
          ? reloadHash("#files")
          : toast(r?.message || "Falha ao vincular contrato.", "error"),
      );
    });
    // Contract viewer: shows the file attached to the linked contract in a popup (no download).
    (function () {
      const $m = $("#pf-contract-viewer");
      if (!$m.length) return;
      let contracts = [];
      try {
        contracts = JSON.parse(String($m.attr("data-contracts") || "[]"));
      } catch (e) {
        contracts = [];
      }
      const libBase = String($m.data("lib-base") || ""),
        $stage = $m.find("[data-pf-cv-stage]"),
        $tabs = $m.find("[data-pf-cv-tabs]");
      let current = null;
      const esc = (v) =>
        $("<div>")
          .text(String(v ?? ""))
          .html();
      const loaders = {};
      const loadLib = (file, globalName) => {
        if (window[globalName]) return Promise.resolve(window[globalName]);
        if (!loaders[file])
          loaders[file] = new Promise((res, rej) => {
            const s = document.createElement("script");
            s.src = `${libBase}/${file}`;
            s.onload = () =>
              window[globalName]
                ? res(window[globalName])
                : rej(new Error("lib"));
            s.onerror = () => rej(new Error("lib"));
            document.head.appendChild(s);
          });
        return loaders[file];
      };
      const busyStage = () =>
        $stage.html(
          '<div class="pf-cv-msg"><span class="spinner-border spinner-border-sm"></span> Carregando o arquivo...</div>',
        );
      const message = (icon, title, text, extra = "") =>
        $stage.html(
          `<div class="pf-cv-msg"><i class="ti ${icon}"></i><strong>${esc(title)}</strong><span>${esc(text)}</span>${extra}</div>`,
        );
      const frameHtml = (html, css = "") => {
        const doc = `<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:28px 36px;color:#1f2937;line-height:1.55;background:#fff}img{max-width:100%}table{border-collapse:collapse}td,th{border:1px solid #d9dee6;padding:4px 8px;font-size:13px;vertical-align:top}${css}</style></head><body>${html}</body></html>`;
        const f = document.createElement("iframe");
        f.className = "pf-cv-frame";
        f.setAttribute("sandbox", "");
        f.srcdoc = doc;
        $stage.empty().append(f);
      };
      const fetchBuf = (url) =>
        fetch(url, { credentials: "same-origin" }).then((r) => {
          if (!r.ok) throw new Error(String(r.status));
          return r.arrayBuffer();
        });
      const kind = (d) => {
        const e = String(d.ext || "").toLowerCase(),
          m = String(d.mime || "").toLowerCase();
        if (e === "pdf" || m === "application/pdf") return "pdf";
        if (
          m.startsWith("image/") ||
          ["png", "jpg", "jpeg", "gif", "webp", "bmp", "svg"].includes(e)
        )
          return "image";
        if (["docx"].includes(e)) return "docx";
        if (["xlsx", "xlsm", "xls", "ods", "csv"].includes(e)) return "sheet";
        if (m.startsWith("video/") || ["mp4", "webm", "ogg"].includes(e))
          return "video";
        if (m.startsWith("audio/") || ["mp3", "wav", "m4a"].includes(e))
          return "audio";
        if (
          m.startsWith("text/") ||
          [
            "txt",
            "log",
            "md",
            "json",
            "xml",
            "html",
            "htm",
            "ini",
            "conf",
            "yml",
            "yaml",
          ].includes(e)
        )
          return "text";
        return "other";
      };
      const show = (d) => {
        current = d;
        $tabs.find("[data-doc]").each(function () {
          $(this).toggleClass(
            "is-active",
            Number($(this).data("doc")) === d.id &&
              Number($(this).data("contract")) === d.contract.id,
          );
        });
        const c = d.contract;
        $m.find("[data-pf-cv-title]").text(c.name);
        $m.find("[data-pf-cv-sub]").text(
          [
            c.number ? `Nº ${c.number}` : "",
            c.begin_date
              ? `Início ${String(c.begin_date).slice(0, 10).split("-").reverse().join("/")}`
              : "",
            d.filename || "",
          ]
            .filter(Boolean)
            .join(" · "),
        );
        $m.find("[data-pf-cv-glpi]").attr("href", c.url);
        const $nt = $m.find("[data-pf-cv-newtab]");
        if (d.id) {
          $nt.removeClass("d-none").attr("href", d.view_url);
        } else {
          $nt.addClass("d-none");
        }
        if (!d.id) {
          message(
            "ti-file-off",
            "Este contrato não tem arquivo anexado",
            "Anexe o PDF (ou outro arquivo) na aba Documentos do contrato no GLPI para vê-lo aqui.",
            `<a class="btn btn-outline-primary btn-sm mt-2" href="${esc(c.documents_url)}" target="_blank" rel="noopener"><i class="ti ti-paperclip"></i> Anexar no contrato</a>`,
          );
          return;
        }
        if (!d.has_file) {
          if (d.link) {
            message(
              "ti-link",
              "Documento externo",
              d.link,
              `<a class="btn btn-outline-primary btn-sm mt-2" href="${esc(d.link)}" target="_blank" rel="noopener noreferrer"><i class="ti ti-external-link"></i> Abrir link</a>`,
            );
          } else {
            message(
              "ti-file-off",
              "Documento sem arquivo",
              "O documento vinculado ao contrato não tem arquivo.",
            );
          }
          return;
        }
        const k = kind(d);
        busyStage();
        if (k === "pdf") {
          const f = document.createElement("iframe");
          f.className = "pf-cv-frame";
          f.src = d.view_url + "#view=FitH";
          f.title = d.name;
          $stage.empty().append(f);
          return;
        }
        if (k === "image") {
          $stage.html(
            `<div class="pf-cv-image"><img src="${esc(d.view_url)}&img=1" alt="${esc(d.name)}"></div>`,
          );
          return;
        }
        if (k === "video") {
          $stage.html(
            `<div class="pf-cv-image"><video src="${esc(d.view_url)}" controls></video></div>`,
          );
          return;
        }
        if (k === "audio") {
          $stage.html(
            `<div class="pf-cv-msg"><i class="ti ti-music"></i><strong>${esc(d.name)}</strong><audio src="${esc(d.view_url)}" controls></audio></div>`,
          );
          return;
        }
        if (k === "text") {
          fetch(d.view_url, { credentials: "same-origin" })
            .then((r) => {
              if (!r.ok) throw new Error();
              return r.text();
            })
            .then((txt) =>
              $stage.html(`<pre class="pf-cv-text">${esc(txt)}</pre>`),
            )
            .catch(() =>
              message(
                "ti-alert-triangle",
                "Não foi possível abrir o arquivo",
                'Tente "Abrir em nova aba".',
              ),
            );
          return;
        }
        if (k === "docx") {
          Promise.all([
            loadLib("mammoth.browser.min.js", "mammoth"),
            fetchBuf(d.view_url),
          ])
            .then(([mm, buf]) => mm.convertToHtml({ arrayBuffer: buf }))
            .then((r) =>
              frameHtml(
                r.value || "<p>(documento vazio)</p>",
                "body{max-width:900px;margin:0 auto}",
              ),
            )
            .catch(() =>
              message(
                "ti-alert-triangle",
                "Não foi possível exibir o documento",
                'Tente "Abrir em nova aba".',
              ),
            );
          return;
        }
        if (k === "sheet") {
          Promise.all([
            loadLib("xlsx.full.min.js", "XLSX"),
            fetchBuf(d.view_url),
          ])
            .then(([X, buf]) => {
              const wb = X.read(buf, { type: "array" });
              const parts = wb.SheetNames.map(
                (n, i) =>
                  `<h3 style="margin:${i ? "28px" : "0"} 0 10px;font-size:15px">${esc(n)}</h3>` +
                  X.utils.sheet_to_html(wb.Sheets[n], {
                    header: "",
                    footer: "",
                  }),
              );
              frameHtml(
                parts.join(""),
                "body{padding:20px}table{min-width:60%}tr:nth-child(even) td{background:#f8fafc}",
              );
            })
            .catch(() =>
              message(
                "ti-alert-triangle",
                "Não foi possível exibir a planilha",
                'Tente "Abrir em nova aba".',
              ),
            );
          return;
        }
        message(
          "ti-file-unknown",
          `Pré-visualização indisponível para .${d.ext || "?"}`,
          'Este tipo de arquivo não pode ser exibido no navegador. Use "Abrir em nova aba" para abrir com o aplicativo do seu computador.',
        );
      };
      const docs = [];
      contracts.forEach((c) => {
        (c.documents && c.documents.length ? c.documents : [{ id: 0 }]).forEach(
          (d) => docs.push({ ...d, contract: c }),
        );
      });
      if (docs.length > 1) {
        $tabs
          .removeClass("d-none")
          .html(
            docs
              .map(
                (d) =>
                  `<button type="button" data-doc="${d.id}" data-contract="${d.contract.id}"><i class="ti ti-${d.id ? "file-text" : "file-off"}"></i><span>${esc(contracts.length > 1 ? `${d.contract.name} · ` : "")}${esc(d.id ? d.name : "Sem arquivo")}</span></button>`,
              )
              .join(""),
          );
        $tabs.on("click", "[data-doc]", function () {
          const id = Number($(this).data("doc")),
            cid = Number($(this).data("contract"));
          const d = docs.find((x) => x.id === id && x.contract.id === cid);
          if (d) show(d);
        });
      }
      $m.on("shown.bs.modal", function () {
        if (!current && docs.length) show(docs[0]);
      });
      $m.find("[data-pf-cv-unlink]").on("click", function () {
        if (!current) return;
        const c = current.contract;
        if (
          !confirm(
            `Desvincular o contrato "${c.name}" deste projeto? Depois você pode vincular outro.`,
          )
        )
          return;
        const $b = $(this);
        busy($b, true);
        api(projectEndpoint, {
          action: "contract_unlink",
          project_id: projectId,
          relation_id: c.relation_id,
          _glpi_csrf_token: csrf,
        })
          .done((r) =>
            r?.ok
              ? window.location.reload()
              : toast(r?.message || "Falha ao desvincular contrato.", "error"),
          )
          .fail((x) =>
            toast(
              x.responseJSON?.message || "Falha ao desvincular contrato.",
              "error",
            ),
          )
          .always(() => busy($b, false));
      });
    })();
    $(".pf-contract-unlink").on("click", function () {
      api(projectEndpoint, {
        action: "contract_unlink",
        project_id: projectId,
        relation_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? reloadHash("#files")
          : toast(r?.message || "Falha ao desvincular contrato.", "error"),
      );
    });

    // ===== Status report (Relatório semanal): structured document, automatic or AI narrative =====
    (function () {
      const $root = $("#pf-rp");
      if (!$root.length) return;
      const $doc = $("#pf-rp-doc"),
        $text = $("#pf-weekly-report-text"),
        $meta = $("#pf-report-meta"),
        $prog = $("#pf-rp-progress");
      let current = null,
        scope = "week",
        editing = false;
      const esc = (v) =>
        String(v ?? "").replace(
          /[&<>"']/g,
          (c) =>
            ({
              "&": "&amp;",
              "<": "&lt;",
              ">": "&gt;",
              '"': "&quot;",
              "'": "&#39;",
            })[c],
        );
      const br = (v) => {
        if (!v) return "";
        const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? `${m[3]}/${m[2]}/${m[1]}` : esc(v);
      };
      const STATUS = {
        no_prazo: ["No prazo", "is-ok", "ti-circle-check"],
        atencao: ["Atenção", "is-warn", "ti-alert-triangle"],
        critico: ["Crítico", "is-crit", "ti-alert-octagon"],
      };
      const SEV = {
        alta: ["Alta", "is-crit"],
        media: ["Média", "is-warn"],
        baixa: ["Baixa", "is-low"],
      };
      const variance = (d) =>
        d == null
          ? ""
          : d > 0
            ? `<em class="pf-rp-var is-late">${d}d após o prazo</em>`
            : d < 0
              ? `<em class="pf-rp-var is-early">${-d}d antes</em>`
              : `<em class="pf-rp-var is-ok">no prazo</em>`;
      const sec = (icon, title, body, extra = "") =>
        `<section class="pf-rp-sec ${extra}"><h3><i class="ti ${icon}"></i>${title}</h3>${body}</section>`;
      const empty = (t) => `<p class="pf-rp-empty">${t}</p>`;
      const render = (d) => {
        current = d;
        const k = d.kpis,
          st = STATUS[d.status] || STATUS.no_prazo,
          isAi = d.source === "ai";
        const budget = k.hours_budget
          ? `<div class="pf-rp-budget"><div><span>Horas consumidas</span><strong>${esc(k.hours_total)} <small>de ${esc(k.hours_budget)}</small></strong></div><div class="pf-rp-bar ${k.hours_budget_pct >= 100 ? "is-crit" : k.hours_budget_pct >= 85 ? "is-warn" : ""}"><i style="width:${Math.min(100, k.hours_budget_pct || 0)}%"></i></div><small>${k.hours_budget_pct}% · ${esc(k.hours_balance)}</small></div>`
          : "";
        const kpis = `<div class="pf-rp-kpis">
          <div class="pf-rp-kpi pf-rp-kpi-progress"><div class="pf-rp-ring" style="--p:${k.progress}"><span>${k.progress}%</span></div><div><span>Andamento</span><strong>${k.tasks_done}/${k.tasks_total}</strong><small>tarefas concluídas</small></div></div>
          ${d.scope === "week" ? `<div class="pf-rp-kpi"><i class="ti ti-package"></i><div><span>Entregas no período</span><strong>${k.delivered_period}</strong><small>${k.in_progress} com avanço</small></div></div>` : `<div class="pf-rp-kpi"><i class="ti ti-progress"></i><div><span>Em andamento</span><strong>${k.in_progress}</strong><small>tarefas iniciadas</small></div></div>`}
          <div class="pf-rp-kpi ${k.overdue ? "is-crit" : ""}"><i class="ti ti-alarm"></i><div><span>Em atraso</span><strong>${k.overdue}</strong><small>${k.attention} ponto(s) de atenção</small></div></div>
          <div class="pf-rp-kpi"><i class="ti ti-clock-hour-4"></i><div><span>${d.scope === "week" ? "Horas no período" : "Horas acumuladas"}</span><strong>${esc(d.scope === "week" ? k.hours_period : k.hours_total)}</strong><small>${d.scope === "week" ? `${esc(k.hours_period_exec)} execução · ${esc(k.hours_period_meet)} reuniões` : "execução + reuniões"}</small></div></div>
          ${budget}
        </div>`;
        const highlights = d.highlights?.length
          ? sec(
              "ti-sparkles",
              "Destaques",
              `<ul class="pf-rp-list">${d.highlights.map((h) => `<li>${esc(h)}</li>`).join("")}</ul>`,
            )
          : "";
        const delivered = sec(
          "ti-circle-check",
          "Entregas concluídas",
          d.delivered.length
            ? `<ul class="pf-rp-list is-check">${d.delivered.map((x) => `<li><span><strong>${esc(x.name)}</strong>${x.milestone ? ' <em class="pf-rp-tag">marco</em>' : ""}</span><span class="pf-rp-li-meta">${br(x.date)} ${variance(x.variance_days)}</span></li>`).join("")}</ul>`
            : empty("Nenhuma entrega concluída no período."),
          d.highlights?.length ? "" : "is-wide",
        );
        const risks = sec(
          "ti-alert-triangle",
          "Riscos e pontos de atenção",
          d.risks.length
            ? `<div class="pf-rp-risks">${d.risks
                .map((r) => {
                  const s = SEV[r.severity] || SEV.media;
                  return `<div class="pf-rp-risk ${s[1]}"><div class="pf-rp-risk-head"><strong>${esc(r.title)}</strong><em>${s[0]}</em></div>${r.detail ? `<p>${esc(r.detail)}</p>` : ""}${r.action ? `<p class="pf-rp-action"><i class="ti ti-arrow-right"></i>${esc(r.action)}</p>` : ""}</div>`;
                })
                .join("")}</div>`
            : empty("Nenhum risco identificado nos dados do projeto."),
          d.risks.length > 1 ? "is-wide" : "",
        );
        const progress = sec(
          "ti-progress",
          "Em andamento",
          d.progress.length
            ? `<table class="pf-rp-table"><thead><tr><th>Tarefa</th><th>Responsável</th><th>Andamento</th><th>Prazo</th></tr></thead><tbody>${d.progress.map((x) => `<tr><td><strong>${esc(x.name)}</strong>${x.notes?.length ? `<small>${esc(x.notes[0].text)}</small>` : ""}</td><td>${esc(x.owner || "—")}</td><td><div class="pf-rp-mini"><i style="width:${x.percent}%"></i></div><b>${x.percent}%</b> <span class="pf-rp-state">${esc(x.state)}</span></td><td class="${x.late ? "is-late" : ""}">${x.deadline ? br(x.deadline) : "—"}${x.late ? ' <em class="pf-rp-var is-late">atrasada</em>' : ""}</td></tr>`).join("")}</tbody></table>`
            : empty("Nenhuma tarefa com movimentação no período."),
          "is-wide",
        );
        const meetings = d.meetings.length
          ? sec(
              "ti-calendar-event",
              "Reuniões e decisões",
              `<div class="pf-rp-meetings">${d.meetings.map((m) => `<div class="pf-rp-meeting"><div class="pf-rp-meeting-head"><strong>${esc(m.title)}</strong><span>${br(m.date)} · ${esc(m.duration)}</span></div>${m.summary ? `<p>${esc(m.summary)}</p>` : ""}${m.decisions ? `<p><b>Decisões:</b> ${esc(m.decisions)}</p>` : ""}${m.actions ? `<p><b>Pendências:</b> ${esc(m.actions)}</p>` : ""}</div>`).join("")}</div>`,
              "is-wide",
            )
          : "";
        const next = sec(
          "ti-list-check",
          "Próximos passos",
          d.next_steps.length
            ? `<table class="pf-rp-table"><thead><tr><th>Ação</th><th>Responsável</th><th>Prazo</th></tr></thead><tbody>${d.next_steps.map((n) => `<tr><td><strong>${esc(n.action || n.name)}</strong>${n.state ? ` <span class="pf-rp-state">${esc(n.state)}</span>` : ""}</td><td>${esc(n.owner || "—")}</td><td class="${n.late ? "is-late" : ""}">${n.deadline ? br(n.deadline) : "—"}</td></tr>`).join("")}</tbody></table>`
            : empty("Nenhuma tarefa em aberto."),
          "is-wide",
        );
        const asks = d.client_asks?.length
          ? `<section class="pf-rp-asks"><h3><i class="ti ti-hand-finger"></i>Decisões e apoio necessários do cliente</h3><ul>${d.client_asks.map((a) => `<li>${esc(a)}</li>`).join("")}</ul></section>`
          : "";
        $doc.html(`
          <header class="pf-rp-doc-head">
            <div><div class="pf-rp-kicker">RELATÓRIO DE STATUS${d.scope === "week" ? " SEMANAL" : " DO PROJETO"}</div><h2>${esc(d.project.name)}${d.project.code ? ` <small>${esc(d.project.code)}</small>` : ""}</h2>
            <div class="pf-rp-sub"><span><i class="ti ti-calendar"></i>${esc(d.period_label)}</span>${d.project.manager ? `<span><i class="ti ti-user"></i>${esc(d.project.manager)}</span>` : ""}${d.project.deadline ? `<span><i class="ti ti-flag"></i>Prazo ${br(d.project.deadline)}</span>` : ""}<span class="pf-rp-state-chip" style="--c:${esc(d.project.state_color || "#94a3b8")}"><i></i>${esc(d.project.state)}</span></div></div>
            <div class="pf-rp-status ${st[1]}"><i class="ti ${st[2]}"></i><div><span>Status</span><strong>${st[0]}</strong></div></div>
          </header>
          <div class="pf-rp-headline ${st[1]}">${isAi ? '<i class="ti ti-sparkles" title="Texto gerado por IA a partir dos dados do projeto"></i>' : ""}<p>${esc(d.headline)}</p></div>
          ${kpis}
          ${sec("ti-file-description", "Resumo executivo", `<p class="pf-rp-summary">${esc(d.summary)}</p>`, "is-wide is-summary")}
          ${asks}
          <div class="pf-rp-grid">${highlights}${delivered}${risks}${progress}${meetings}${next}</div>
          <footer class="pf-rp-foot">Gerado pelo Pellissari Project em ${br(d.generated_at)} ${esc(String(d.generated_at || "").slice(11, 16))}${isAi ? " · narrativa por IA, números do sistema" : ""}.</footer>`);
        $text.val(d.text || "").attr("data-pf-lazy", "0");
        setEditing(false);
      };
      const setEditing = (on) => {
        editing = on;
        $doc.toggleClass("d-none", on);
        $text.toggleClass("d-none", !on);
        $("#pf-report-edit")
          .toggleClass("is-active", on)
          .find("span")
          .text(on ? "Ver documento" : "Editar texto");
      };
      const showMeta = (d) => {
        if (!d) {
          $meta.empty();
          return;
        }
        if (d.source === "ai" && d.ai) {
          const a = d.ai;
          $meta.html(
            `<i class="ti ti-sparkles"></i> ${a.cached ? "Mesma análise da última geração (dados sem mudança) · " : ""}${esc(a.model)}${a.used_fallback ? " (reserva)" : ""} · ${(a.ms / 1000).toLocaleString("pt-BR", { maximumFractionDigits: 1 })}s${a.tokens_in ? ` · ${a.tokens_in.toLocaleString("pt-BR")} tokens de entrada / ${a.tokens_out.toLocaleString("pt-BR")} de saída` : ""} · revise antes de enviar.${a.cached ? ' <a href="#" id="pf-rp-force">Gerar nova análise</a>' : ""}`,
          );
        } else
          $meta.html(
            '<i class="ti ti-database"></i> Relatório automático, montado com os dados do projeto.',
          );
      };
      const progress = (on) => {
        $prog.toggleClass("d-none", !on);
        if (!on) return;
        let i = 0;
        const steps = $prog.find("li").removeClass("is-done is-active");
        steps.eq(0).addClass("is-active");
        clearInterval($prog.data("t"));
        $prog.data(
          "t",
          setInterval(() => {
            if (i < steps.length - 1) {
              steps.eq(i).removeClass("is-active").addClass("is-done");
              i++;
              steps.eq(i).addClass("is-active");
            }
          }, 1800),
        );
      };
      const stopProgress = () => {
        clearInterval($prog.data("t"));
        $prog.addClass("d-none");
      };
      const week = () => String($("#pf-report-week").val() || "");
      const generate = () => {
        const $b = $("#pf-report-generate");
        busy($b, true);
        api(projectEndpoint, {
          action: "report_generate",
          project_id: projectId,
          week_start: week(),
          scope,
          _glpi_csrf_token: csrf,
        })
          .done((r) => {
            if (!r?.ok)
              return toast(r?.message || "Falha ao gerar relatório.", "error");
            render(r.report);
            showMeta(r.report);
          })
          .fail((x) =>
            toast(
              x.responseJSON?.message || "Falha ao gerar relatório.",
              "error",
            ),
          )
          .always(() => busy($b, false));
      };
      const generateAi = (force = false) => {
        const $b = $("#pf-report-ai");
        busy($b, true);
        $b.find("i").attr("class", "ti ti-loader-2 pf-spin");
        progress(true);
        api(projectEndpoint, {
          action: "report_ai",
          project_id: projectId,
          week_start: week(),
          scope,
          force: force ? 1 : 0,
          _glpi_csrf_token: csrf,
        })
          .done((r) => {
            if (!r?.ok)
              return toast(r?.message || "Falha ao gerar com IA.", "error");
            render(r.report);
            showMeta(r.report);
            toast(
              r.report.ai?.cached
                ? "Dados sem mudança: mesma análise da última geração."
                : "Relatório gerado com IA.",
            );
          })
          .fail((x) =>
            toast(x.responseJSON?.message || "Falha ao gerar com IA.", "error"),
          )
          .always(() => {
            stopProgress();
            busy($b, false);
            $b.find("i").attr("class", "ti ti-sparkles");
          });
      };
      $("#pf-report-generate").on("click", generate);
      $("#pf-report-ai").on("click", () => generateAi(false));
      $meta.on("click", "#pf-rp-force", (e) => {
        e.preventDefault();
        generateAi(true);
      });
      $root.on("click", "[data-pf-rp-scope]", function () {
        scope = String($(this).data("pf-rp-scope"));
        $root
          .find("[data-pf-rp-scope]")
          .removeClass("is-active")
          .filter(this)
          .addClass("is-active");
        $("#pf-report-week").prop("disabled", scope === "project");
        generate();
      });
      $("#pf-report-week").on("change", generate);
      $("#pf-report-edit").on("click", () => setEditing(!editing));
      const emailHtml = (d) => {
        const k = d.kpis,
          st = STATUS[d.status] || STATUS.no_prazo,
          c = {
            "is-ok": "#2fb344",
            "is-warn": "#f59f00",
            "is-crit": "#d63939",
          }[st[1]];
        const h = (t) =>
          `<h3 style="font:600 14px Arial,sans-serif;color:#1f2937;margin:18px 0 6px;border-bottom:1px solid #e5e7eb;padding-bottom:4px">${t}</h3>`;
        const ul = (a) =>
          a.length
            ? `<ul style="margin:0;padding-left:18px;font:13px/1.5 Arial,sans-serif;color:#374151">${a.map((x) => `<li>${x}</li>`).join("")}</ul>`
            : '<p style="font:13px Arial;color:#6b7280">—</p>';
        return `<div style="max-width:760px;font-family:Arial,sans-serif"><div style="font:600 11px Arial;letter-spacing:.06em;color:#6b7280">RELATÓRIO DE STATUS · ${esc(d.period_label)}</div><h2 style="font:700 20px Arial;color:#111827;margin:4px 0 8px">${esc(d.project.name)} <span style="font:600 12px Arial;color:#fff;background:${c};border-radius:10px;padding:2px 9px;vertical-align:middle">${st[0]}</span></h2><p style="font:600 14px/1.45 Arial;color:#111827;border-left:4px solid ${c};padding:6px 10px;background:#f9fafb;margin:0 0 10px">${esc(d.headline)}</p>
        <table style="border-collapse:collapse;font:12px Arial;color:#374151;margin-bottom:6px"><tr>${[
          ["Andamento", k.progress + "%"],
          ["Concluídas", k.tasks_done + "/" + k.tasks_total],
          ["Em atraso", k.overdue],
          [
            d.scope === "week" ? "Horas no período" : "Horas acumuladas",
            d.scope === "week" ? k.hours_period : k.hours_total,
          ],
        ]
          .concat(
            k.hours_budget
              ? [["Consumo de horas", k.hours_budget_pct + "%"]]
              : [],
          )
          .map(
            ([a, b]) =>
              `<td style="border:1px solid #e5e7eb;padding:6px 10px"><div style="color:#6b7280;font-size:11px">${a}</div><b style="font-size:15px;color:#111827">${esc(b)}</b></td>`,
          )
          .join("")}</tr></table>
        ${h("Resumo executivo")}<p style="font:13px/1.55 Arial;color:#374151;margin:0">${esc(d.summary)}</p>
        ${d.client_asks?.length ? h("Decisões e apoio necessários do cliente") + ul(d.client_asks.map(esc)) : ""}
        ${d.highlights?.length ? h("Destaques") + ul(d.highlights.map(esc)) : ""}
        ${h("Entregas concluídas")}${ul(d.delivered.map((x) => `<b>${esc(x.name)}</b> — ${br(x.date)}`))}
        ${h("Riscos e pontos de atenção")}${ul(d.risks.map((r) => `<b>${esc(r.title)}</b> (${(SEV[r.severity] || SEV.media)[0]})${r.detail ? " — " + esc(r.detail) : ""}${r.action ? `<br><i>Ação: ${esc(r.action)}</i>` : ""}`))}
        ${h("Próximos passos")}${ul(d.next_steps.map((n) => `${esc(n.action || n.name)}${n.owner ? " — " + esc(n.owner) : ""}${n.deadline ? " · até " + br(n.deadline) : ""}`))}
        <p style="font:11px Arial;color:#9ca3af;margin-top:16px">Gerado pelo Pellissari Project.</p></div>`;
      };
      $("#pf-report-copy").on("click", async function () {
        const text = String($text.val() || "");
        try {
          if (current && !editing && window.ClipboardItem) {
            await navigator.clipboard.write([
              new ClipboardItem({
                "text/html": new Blob([emailHtml(current)], {
                  type: "text/html",
                }),
                "text/plain": new Blob([text], { type: "text/plain" }),
              }),
            ]);
            toast("Relatório copiado com formatação: cole no e-mail.");
          } else {
            await navigator.clipboard.writeText(text);
            toast("Relatório copiado.");
          }
        } catch {
          const el = $text[0];
          $text.removeClass("d-none");
          el.select();
          document.execCommand("copy");
          if (!editing) $text.addClass("d-none");
          toast("Relatório copiado.");
        }
      });
      $("#pf-report-print").on("click", () => {
        if (editing) setEditing(false);
        document.body.classList.add("pf-rp-printing");
        setTimeout(() => {
          window.print();
          setTimeout(
            () => document.body.classList.remove("pf-rp-printing"),
            300,
          );
        }, 50);
      });
      $("#pf-report-save").on("click", function () {
        const content =
          editing || !current
            ? String($text.val() || "")
            : JSON.stringify(
                Object.assign({}, current, { text: String($text.val() || "") }),
              );
        api(projectEndpoint, {
          action: "report_save",
          project_id: projectId,
          week_start: current?.week_start || week(),
          content,
          _glpi_csrf_token: csrf,
        }).done((r) =>
          r?.ok
            ? toast("Versão salva.")
            : toast(r?.message || "Falha ao salvar relatório.", "error"),
        );
      });
      $(".pf-saved-reports button").on("click", function () {
        const raw = String($(this).attr("data-report-content") || "");
        let d = null;
        try {
          d = raw.trim().startsWith("{") ? JSON.parse(raw) : null;
        } catch {}
        if (d && d.kpis) {
          render(d);
          showMeta(d);
        } else {
          current = null;
          $text.val(raw).attr("data-pf-lazy", "0");
          setEditing(true);
          $meta.html('<i class="ti ti-history"></i> Versão salva em texto.');
        }
        $("html,body").animate({ scrollTop: $root.offset().top - 80 }, 200);
      });
    })();
    activate(initialTab);

    $(".pf-document-upload").on("submit", function (e) {
      e.preventDefault();
      const fd = new FormData(this),
        $f = $(this),
        $b = $f.find("button");
      fd.set("action", "upload");
      fd.set("_glpi_csrf_token", csrf);
      fd.set("itemtype", String($f.data("itemtype")));
      fd.set("items_id", String($f.data("items-id")));
      busy($b, true);
      upload(docEndpoint, fd)
        .done((r) =>
          r?.ok
            ? reloadHash("#files")
            : toast(r?.message || "Falha no upload.", "error"),
        )
        .fail((x) =>
          toast(x.responseJSON?.message || "Falha no upload.", "error"),
        )
        .always(() => busy($b, false));
    });
    $(".pf-document-unlink").on("click", function () {
      if (
        !confirm(
          "Desvincular o documento? O arquivo original não será excluído.",
        )
      )
        return;
      api(docEndpoint, {
        action: "unlink",
        itemtype: String($(this).data("itemtype")),
        items_id: Number($(this).data("items-id")),
        relation_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? reloadHash("#files")
          : toast(r?.message || "Falha ao desvincular.", "error"),
      );
    });

    $(".pf-cost-remove").on("click", function () {
      const $b = $(this);
      if (!window.confirm("Remover este lançamento de custo?")) return;
      busy($b, true);
      api(projectEndpoint, {
        action: "cost_remove",
        project_id: Number($b.data("project-id")),
        cost_id: Number($b.data("id")),
        _glpi_csrf_token: csrf,
      })
        .done((r) =>
          r?.ok
            ? reloadHash("#hours")
            : toast(r?.message || "Falha ao remover custo.", "error"),
        )
        .fail((x) =>
          toast(x.responseJSON?.message || "Falha ao remover custo.", "error"),
        )
        .always(() => busy($b, false));
    });
    $(".pf-cost-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this);
      api(projectEndpoint, {
        action: "cost_add",
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      }).done((r) =>
        r?.ok
          ? reloadHash("#hours")
          : toast(r?.message || "Falha ao lançar custo.", "error"),
      );
    });
  }

  function initTask() {
    const $app = $("#projectflow-task");
    if (!$app.length) return;
    if (String($app.data("embed")) === "1") {
      // Popup mode: other tasks stay in the popup; project, tickets, assets... open in the main window.
      $(document).on("click", "a[href]", function (e) {
        const href = String($(this).attr("href") || "");
        if (
          !href ||
          href.startsWith("#") ||
          href.startsWith("javascript:") ||
          $(this).attr("target") ||
          href.includes("document.send.php")
        )
          return;
        const url = new URL(href, window.location.href);
        if (url.pathname.endsWith("/plugins/projectflow/front/task.php")) {
          url.searchParams.set("embed", "1");
          e.preventDefault();
          window.location.href = url.toString();
          return;
        }
        e.preventDefault();
        (window.top || window).location.href = url.toString();
      });
      try {
        window.parent?.postMessage(
          { type: "projectflow-task-loaded", title: document.title },
          window.location.origin,
        );
      } catch {}
    }
    const taskId = Number($app.data("task-id")),
      taskEndpoint = String($app.data("task-endpoint")),
      docEndpoint = String($app.data("document-endpoint")),
      csrf = csrfFor($app);
    initMemberSelector($app);
    const tabKey = `projectflow.task.${taskId}.tab`;
    const activateTaskPane = (name) => {
      name = name || "processing";
      if (!$(`[data-pf-task-pane="${name}"]`).length) name = "processing";
      $("[data-pf-task-tab]")
        .removeClass("is-active")
        .filter(`[data-pf-task-tab="${name}"]`)
        .addClass("is-active");
      $("[data-pf-task-pane]")
        .removeClass("is-active")
        .filter(`[data-pf-task-pane="${name}"]`)
        .addClass("is-active");
      localStorage.setItem(tabKey, name);
    };
    const reloadTaskPane = (name) => {
      activateTaskPane(name);
      history.replaceState(null, "", `#${name}`);
      window.location.reload();
    };
    $("[data-pf-task-tab]").on("click", function () {
      const name = String($(this).data("pf-task-tab") || "processing");
      activateTaskPane(name);
      history.replaceState(null, "", `#${name}`);
    });
    activateTaskPane(
      location.hash.slice(1) || localStorage.getItem(tabKey) || "processing",
    );

    const currentUserId = Number($app.data("current-user-id") || 0);
    const applyTimeFilter = (kind, scope) => {
      const $rows = $(`[data-pf-time-kind="${kind}"]`),
        $empty = $(`[data-pf-time-empty="${kind}"]`),
        $total = $(`[data-pf-time-total="${kind}"]`);
      let visible = 0;
      $rows.each(function () {
        const mine = Number($(this).data("user-id") || 0) === currentUserId,
          show = scope === "all" || mine;
        $(this).toggleClass("d-none", !show);
        if (show) visible++;
      });
      if ($rows.length) $empty.toggleClass("d-none", visible > 0);
      else $empty.addClass("d-none");
      $total.text(
        scope === "all"
          ? String($total.attr("data-all-total") || "0 min")
          : String($total.attr("data-mine-total") || "0 min"),
      );
      $(`[data-pf-time-filter][data-time-kind="${kind}"]`)
        .removeClass("is-active active")
        .filter(`[data-pf-time-filter="${scope}"]`)
        .addClass("is-active active");
    };
    $("[data-pf-time-filter]").on("click", function () {
      applyTimeFilter(
        String($(this).data("time-kind") || ""),
        String($(this).data("pf-time-filter") || "mine"),
      );
    });
    applyTimeFilter("execution", "mine");
    applyTimeFilter("meeting", "mine");

    const quickModes = {
      reply: { title: "Responder", icon: "ti-message-circle" },
      execution: {
        title: "Adicionar execução",
        edit: "Editar execução",
        icon: "ti-player-play",
      },
      meeting: {
        title: "Adicionar reunião",
        edit: "Editar reunião",
        icon: "ti-calendar-event",
      },
      dependency: { title: "Adicionar dependência", icon: "ti-arrows-join" },
      cost: { title: "Adicionar custo", icon: "ti-currency-dollar" },
    };
    const quickEditField = { execution: "worklog_id", meeting: "meeting_id" };
    const setQuickEdit = (mode, id) => {
      const $f = $(`[data-pf-quick-mode="${mode}"]`),
        field = quickEditField[mode];
      if (!field || !$f.length) return;
      $f.find(`[name="${field}"]`).val(String(id || 0));
      const $b = $f.find("button[data-label-add]");
      $b.find("span").text(
        String($b.attr(id > 0 ? "data-label-edit" : "data-label-add") || ""),
      );
      $("#pf-quick-composer-title").text(
        id > 0 ? quickModes[mode].edit : quickModes[mode].title,
      );
    };
    const openQuickComposer = (mode, editId = 0) => {
      if (!quickModes[mode]) mode = "reply";
      const meta = quickModes[mode],
        $box = $("#pf-quick-composer");
      if (!$box.length) return;
      $box.removeClass("d-none");
      $box
        .find(".pf-quick-mode")
        .addClass("d-none")
        .filter(`[data-pf-quick-mode="${mode}"]`)
        .removeClass("d-none");
      $("#pf-quick-composer-title").text(meta.title);
      $("#pf-quick-composer-icon").attr("class", `ti ${meta.icon}`);
      activateTaskPane("processing");
      const $f = $box.find(`[data-pf-quick-mode="${mode}"]`);
      if (quickEditField[mode]) {
        if (
          !editId &&
          Number($f.find(`[name="${quickEditField[mode]}"]`).val() || 0) > 0
        ) {
          $f[0].reset();
        }
        setQuickEdit(mode, editId);
      }
      setMessage($f, "");
      setTimeout(() => {
        $f.find('textarea,input:not([type="hidden"]),select')
          .first()
          .trigger("focus");
      }, 60);
    };
    $("[data-pf-quick-open]").on("click", function () {
      openQuickComposer(String($(this).data("pf-quick-open") || "reply"));
    });
    $(".pf-quick-composer-close").on("click", () =>
      $("#pf-quick-composer").addClass("d-none"),
    );

    $('#pf-task-control-form [name="percent_done"]').on(
      "input change",
      function () {
        const pct = Math.max(0, Math.min(100, Number($(this).val() || 0)));
        $(this).closest("label").find(".progress-bar").css("width", `${pct}%`);
      },
    );
    $("#pf-task-control-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $('button[form="pf-task-control-form"]').add(
          $f.find('button[type="submit"]'),
        );
      busy($b, true);
      api(taskEndpoint, {
        action: "update",
        id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f, [
          "attention",
          "reminder_email",
          "reminder_browser",
        ]),
      })
        .done((r) =>
          r?.ok
            ? window.location.reload()
            : setMessage($f, r?.message || "Falha ao salvar tarefa.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao salvar tarefa.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $("#pf-activity-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find("button");
      busy($b, true);
      api(taskEndpoint, {
        action: "activity_add",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? reloadTaskPane("processing")
            : setMessage(
                $f,
                r?.message || "Falha ao registrar comentário.",
                true,
              ),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao registrar comentário.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $(".pf-activity-delete").on("click", function () {
      if (!confirm("Remover este comentário?")) return;
      api(taskEndpoint, {
        action: "activity_delete",
        task_id: taskId,
        activity_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? reloadTaskPane("processing")
          : toast(r?.message || "Falha ao remover.", "error"),
      );
    });
    $(document).on("click", ".pf-worklog-edit", function () {
      const $b = $(this),
        $f = $("#pf-worklog-quick-form");
      if (!$f.length) return;
      const total = Number($b.data("minutes") || 0);
      openQuickComposer("execution", Number($b.data("id") || 0));
      $f.find('[name="work_date"]').val(String($b.attr("data-date") || ""));
      $f.find('[name="hours"]').val(String(Math.floor(total / 60)));
      $f.find('[name="minutes"]').val(String(total % 60));
      $f.find('[name="comment"]')
        .val(String($b.attr("data-comment") || ""))
        .trigger("focus");
    });
    $(".pf-worklog-edit-cancel").on("click", function () {
      $("#pf-worklog-edit-form").addClass("d-none");
    });
    $("#pf-worklog-edit-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"]');
      busy($b, true);
      api(taskEndpoint, {
        action: "worklog_update",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? reloadTaskPane("processing")
            : setMessage($f, r?.message || "Falha ao salvar execução.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao salvar execução.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $("#pf-worklog-quick-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"],button:not([type])').last(),
        editId = Number($f.find('[name="worklog_id"]').val() || 0);
      busy($b, true);
      api(taskEndpoint, {
        action: editId > 0 ? "worklog_update" : "worklog_add",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? reloadTaskPane("processing")
            : setMessage($f, r?.message || "Falha ao salvar execução.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao salvar execução.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $("#pf-meeting-quick-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"],button:not([type])').last(),
        data = formDataObject($f),
        hours = Math.max(
          0,
          Number(String(data.hours || 0).replace(",", ".")) || 0,
        ),
        minutes = Math.max(0, Math.min(59, Number(data.minutes || 0) || 0)),
        duration = Math.round(hours * 60) + minutes,
        editId = Number(data.meeting_id || 0);
      if (duration <= 0) {
        setMessage($f, "Informe uma duração maior que zero.", true);
        return;
      }
      busy($b, true);
      delete data.hours;
      delete data.minutes;
      api(taskEndpoint, {
        action: editId > 0 ? "meeting_update" : "meeting_add",
        task_id: taskId,
        duration_minutes: duration,
        _glpi_csrf_token: csrf,
        ...data,
      })
        .done((r) =>
          r?.ok
            ? reloadTaskPane("processing")
            : setMessage($f, r?.message || "Falha ao salvar reunião.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao salvar reunião.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $(".pf-worklog-delete").on("click", function () {
      if (!confirm("Remover este lançamento de horas?")) return;
      api(taskEndpoint, {
        action: "worklog_delete",
        task_id: taskId,
        worklog_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao remover.", "error"),
      );
    });
    const resetTaskMeetingForm = () => {
      const $f = $("#pf-task-meeting-form");
      if (!$f.length) return;
      $f[0].reset();
      $f.find('[name="meeting_id"]').val("0");
      $f.find('[name="duration_minutes"]').val("60");
      const now = new Date();
      now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
      $f.find('[name="start_at"]').val(now.toISOString().slice(0, 16));
      setMessage($f, "");
      $f.addClass("d-none");
    };
    $(".pf-task-meeting-reset").on("click", resetTaskMeetingForm);
    $(document).on("click", ".pf-task-meeting-edit", function () {
      const $b = $(this),
        $f = $("#pf-meeting-quick-form");
      if (!$f.length) return;
      const total = Number($b.attr("data-duration") || 60);
      openQuickComposer("meeting", Number($b.data("id") || 0));
      $f.find('[name="title"]').val(String($b.attr("data-title") || ""));
      $f.find('[name="start_at"]').val(String($b.attr("data-start") || ""));
      $f.find('[name="hours"]').val(String(Math.floor(total / 60)));
      $f.find('[name="minutes"]').val(String(total % 60));
      $f.find('[name="participants"]').val(
        String($b.attr("data-participants") || ""),
      );
      $f.find('[name="summary"]').val(String($b.attr("data-summary") || ""));
      $f.find('[name="decisions"]').val(
        String($b.attr("data-decisions") || ""),
      );
      $f.find('[name="actions"]').val(String($b.attr("data-actions") || ""));
      $f.find('[name="title"]').trigger("focus");
    });
    $("#pf-task-meeting-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"]'),
        meetingId = Number($f.find('[name="meeting_id"]').val() || 0),
        action = "meeting_update";
      if (meetingId <= 0) {
        $f.addClass("d-none");
        return;
      }
      busy($b, true);
      api(taskEndpoint, {
        action,
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? window.location.reload()
            : setMessage($f, r?.message || "Falha ao salvar reunião.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao salvar reunião.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $(".pf-task-meeting-delete").on("click", function () {
      if (!confirm("Remover esta reunião e o lançamento de horas relacionado?"))
        return;
      api(taskEndpoint, {
        action: "meeting_delete",
        task_id: taskId,
        meeting_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao remover reunião.", "error"),
      );
    });
    $("#pf-subtask-create-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"],button:not([type])').last();
      busy($b, true);
      api(taskEndpoint, {
        action: "create",
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? window.location.reload()
            : setMessage($f, r?.message || "Falha ao criar subtarefa.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao criar subtarefa.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $("#pf-task-team-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this);
      api(taskEndpoint, {
        action: "team_add",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao adicionar executor.", "error"),
      );
    });
    $(".pf-task-team-remove").on("click", function () {
      api(taskEndpoint, {
        action: "team_remove",
        task_id: taskId,
        relation_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao remover executor.", "error"),
      );
    });
    let assetSearchTimer = 0;
    const clearAssetSelection = () => {
      const $f = $("#pf-asset-form");
      $f.find('[name="items_id"]').val("");
      $f.find(".pf-asset-search-results").empty().removeClass("is-open");
    };
    $(".pf-asset-type").on("change", function () {
      const $f = $("#pf-asset-form");
      $f.find(".pf-asset-query").val("");
      clearAssetSelection();
    });
    $(".pf-asset-query").on("input", function () {
      const $q = $(this),
        $f = $("#pf-asset-form"),
        q = String($q.val() || "").trim(),
        type = String($f.find('[name="itemtype"]').val() || ""),
        $results = $f.find(".pf-asset-search-results");
      $f.find('[name="items_id"]').val("");
      clearTimeout(assetSearchTimer);
      if (!q) {
        $results.empty().removeClass("is-open");
        return;
      }
      assetSearchTimer = setTimeout(() => {
        api(taskEndpoint, {
          action: "asset_search",
          task_id: taskId,
          itemtype: type,
          q,
          _glpi_csrf_token: csrf,
        })
          .done((r) => {
            const items = Array.isArray(r?.items) ? r.items : [];
            $results.empty();
            items.forEach((item) =>
              $('<button type="button" class="pf-asset-result"></button>')
                .text(item.label || item.name || `#${item.id}`)
                .attr("data-id", item.id)
                .attr("data-name", item.name || "")
                .appendTo($results),
            );
            if (!items.length)
              $(
                '<div class="pf-asset-no-result">Nenhum ativo encontrado.</div>',
              ).appendTo($results);
            $results.addClass("is-open");
          })
          .fail(() =>
            $results
              .empty()
              .append(
                '<div class="pf-asset-no-result">Falha ao buscar ativos.</div>',
              )
              .addClass("is-open"),
          );
      }, 250);
    });
    $("#pf-asset-form").on("click", ".pf-asset-result", function () {
      const $b = $(this),
        $f = $("#pf-asset-form");
      $f.find('[name="items_id"]').val(String($b.data("id") || ""));
      $f.find(".pf-asset-query").val(String($b.attr("data-name") || $b.text()));
      $f.find(".pf-asset-search-results").empty().removeClass("is-open");
    });
    $("#pf-asset-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this);
      if (!Number($f.find('[name="items_id"]').val() || 0)) {
        toast("Selecione um ativo nos resultados da busca.", "error");
        return;
      }
      api(taskEndpoint, {
        action: "asset_add",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao vincular ativo.", "error"),
      );
    });
    $(".pf-asset-remove").on("click", function () {
      api(taskEndpoint, {
        action: "asset_remove",
        task_id: taskId,
        relation_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao desvincular ativo.", "error"),
      );
    });
    $("#pf-ticket-link-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this);
      api(taskEndpoint, {
        action: "ticket_link",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao vincular chamado.", "error"),
      );
    });
    $(".pf-ticket-unlink").on("click", function () {
      api(taskEndpoint, {
        action: "ticket_unlink",
        task_id: taskId,
        relation_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao desvincular chamado.", "error"),
      );
    });
    $("#pf-ticket-create-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find('button[type="submit"],button:not([type])').last();
      busy($b, true);
      api(taskEndpoint, {
        action: "ticket_create",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? window.location.reload()
            : setMessage($f, r?.message || "Falha ao criar chamado.", true),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message || "Falha ao criar chamado.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    $("#pf-cost-quick-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find("button").last();
      busy($b, true);
      api(taskEndpoint, {
        action: "cost_add",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) => {
          if (!r?.ok) return setMessage($f, r?.message || "Falha ao lançar o custo.", true);
          try {
            sessionStorage.setItem("pf-toast", r.message || "Custo lançado.");
          } catch {}
          reloadTaskPane("processing");
        })
        .fail((x) =>
          setMessage($f, x.responseJSON?.message || "Falha ao lançar o custo.", true),
        )
        .always(() => busy($b, false));
    });
    $("#pf-dependency-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        $b = $f.find("button").last();
      busy($b, true);
      api(taskEndpoint, {
        action: "dependency_add",
        task_id: taskId,
        _glpi_csrf_token: csrf,
        ...formDataObject($f),
      })
        .done((r) =>
          r?.ok
            ? reloadTaskPane("processing")
            : setMessage(
                $f,
                r?.message || "Falha ao adicionar dependência.",
                true,
              ),
        )
        .fail((x) =>
          setMessage(
            $f,
            x.responseJSON?.message ||
              "Dependência inválida, duplicada ou criaria um ciclo.",
            true,
          ),
        )
        .always(() => busy($b, false));
    });
    // Task timeline: filter by kind (comments, executions, meetings, dependencies) and "only mine".
    const applyTimelineFilter = () => {
      const kind = String(
          $("[data-pf-tl-filter].is-active").data("pf-tl-filter") || "all",
        ),
        mine = $("[data-pf-tl-mine]").is(":checked");
      let visible = 0;
      $(".pf-tl-item").each(function () {
        const $i = $(this),
          show =
            (kind === "all" || $i.data("pf-tl-kind") === kind) &&
            (!mine || Number($i.data("user-id") || 0) === currentUserId);
        $i.toggleClass("d-none", !show);
        if (show) visible++;
      });
      $("[data-pf-tl-empty]").toggleClass(
        "d-none",
        visible > 0 || !$(".pf-tl-item").length,
      );
    };
    $("[data-pf-tl-filter]").on("click", function () {
      $("[data-pf-tl-filter]").removeClass("is-active");
      $(this).addClass("is-active");
      applyTimelineFilter();
    });
    $("[data-pf-tl-mine]").on("change", applyTimelineFilter);
    $(".pf-dependency-remove").on("click", function () {
      if (!confirm("Remover esta dependência?")) return;
      api(taskEndpoint, {
        action: "dependency_remove",
        task_id: taskId,
        link_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao remover dependência.", "error"),
      );
    });
    $(".pf-document-upload").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this),
        fd = new FormData(this),
        $b = $f.find("button");
      fd.set("action", "upload");
      fd.set("_glpi_csrf_token", csrf);
      fd.set("itemtype", String($f.data("itemtype")));
      fd.set("items_id", String($f.data("items-id")));
      busy($b, true);
      upload(docEndpoint, fd)
        .done((r) =>
          r?.ok
            ? window.location.reload()
            : toast(r?.message || "Falha no upload.", "error"),
        )
        .fail((x) =>
          toast(x.responseJSON?.message || "Falha no upload.", "error"),
        )
        .always(() => busy($b, false));
    });
    $(".pf-document-unlink").on("click", function () {
      if (!confirm("Desvincular o documento?")) return;
      api(docEndpoint, {
        action: "unlink",
        itemtype: String($(this).data("itemtype")),
        items_id: Number($(this).data("items-id")),
        relation_id: Number($(this).data("id")),
        _glpi_csrf_token: csrf,
      }).done((r) =>
        r?.ok
          ? window.location.reload()
          : toast(r?.message || "Falha ao desvincular.", "error"),
      );
    });
  }

  function openTaskModal(url) {
    let $m = $("#pf-task-popup");
    if (!$m.length) {
      $m = $(
        `<div class="modal fade" id="pf-task-popup" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-fullscreen-lg-down pf-task-popup-dialog"><div class="modal-content pf-modal-content"><div class="modal-header py-2"><h2 class="modal-title fs-5 text-truncate" id="pf-task-popup-title">Tarefa</h2><div class="ms-auto d-flex align-items-center gap-2"><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div></div><div class="modal-body p-0"><div class="pf-task-popup-loading"><i class="ti ti-loader-2 pf-spin"></i> Carregando tarefa…</div><iframe id="pf-task-popup-frame" title="Tarefa" src="about:blank"></iframe></div></div></div></div>`,
      ).appendTo("body");
      $m.on("hidden.bs.modal", function () {
        const changed = Number($m.data("loads") || 0) > 1;
        $("#pf-task-popup-frame").attr("src", "about:blank");
        if (changed) window.location.reload();
      });
      $("#pf-task-popup-frame").on("load", function () {
        const src = String(this.getAttribute("src") || "");
        if (src === "about:blank") return;
        $m.data("loads", Number($m.data("loads") || 0) + 1);
        $m.find(".pf-task-popup-loading").addClass("d-none");
        try {
          const t = this.contentDocument?.title || "";
          if (t)
            $("#pf-task-popup-title").text(t.replace(/\s*-\s*GLPI\s*$/, ""));
        } catch {}
      });
    }
    const embedUrl = new URL(url, window.location.href);
    embedUrl.searchParams.set("embed", "1");
    const fullUrl = new URL(url, window.location.href);
    fullUrl.searchParams.delete("embed");
    $m.data("loads", 0);
    $m.find(".pf-task-popup-loading").removeClass("d-none");
    $("#pf-task-popup-title").text("Tarefa");
    $("#pf-task-popup-frame").attr("src", embedUrl.toString());
    if (window.bootstrap?.Modal)
      window.bootstrap.Modal.getOrCreateInstance($m[0]).show();
    else window.location.href = fullUrl.toString();
  }

  // Tasks never open as a full page: every link to a task (Kanban, lists, timeline, summary...) opens
  // the popup, and ?task=<id> in the URL (redirect from the old full page, new task) opens it on load.
  function initTaskPopupLinks() {
    if (
      $("#projectflow-task").length &&
      String($("#projectflow-task").data("embed")) === "1"
    )
      return;
    const isTaskUrl = (href) => {
      try {
        const u = new URL(href, window.location.href);
        return (
          u.origin === window.location.origin &&
          u.pathname.endsWith("/plugins/projectflow/front/task.php") &&
          !u.searchParams.get("embed")
        );
      } catch {
        return false;
      }
    };
    $(document).on("click", "a[href]", function (e) {
      const href = String($(this).attr("href") || "");
      if (!isTaskUrl(href)) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      openTaskModal(href);
    });
    const params = new URLSearchParams(window.location.search),
      tid = Number(params.get("task") || 0);
    if (tid > 0) {
      const base = String(
        $("[data-task-endpoint]").first().data("task-endpoint") ||
          "/plugins/projectflow/ajax/task.php",
      ).replace("/ajax/task.php", "/front/task.php");
      openTaskModal(`${base}?id=${tid}`);
      try {
        params.delete("task");
        const u = new URL(window.location.href);
        u.search = params.toString();
        window.history.replaceState(null, "", u.toString());
      } catch {}
    }
  }

  function initTaskCenter() {
    const $app = $("#projectflow-task-center");
    if (!$app.length) return;
    let quick = "all";
    // Rows open the task in a popup (Ctrl/Cmd/middle click keep the normal new-tab behaviour).
    $app.on(
      "click",
      '.pf-task-ticket-row a[href*="/front/task.php"]',
      function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
        e.preventDefault();
        openTaskModal(String($(this).attr("href")));
      },
    );
    $app.on("click", ".pf-task-ticket-row", function (e) {
      if ($(e.target).closest("a,button,input,select,label").length) return;
      const href = $(this)
        .find('a[href*="/front/task.php"]')
        .first()
        .attr("href");
      if (href) openTaskModal(String(href));
    });
    const filter = () => {
      const q = String($("#pf-task-center-search").val() || "").toLowerCase(),
        project = String($("#pf-task-center-project").val() || ""),
        state = String($("#pf-task-center-state").val() || ""),
        priority = String($("#pf-task-center-priority").val() || "");
      const now = new Date(),
        today = new Date(now.getFullYear(), now.getMonth(), now.getDate()),
        tomorrow = new Date(today);
      tomorrow.setDate(tomorrow.getDate() + 1);
      const next7 = new Date(today);
      next7.setDate(next7.getDate() + 8);
      let count = 0;
      $(".pf-task-ticket-row").each(function () {
        const $r = $(this),
          due = String($r.data("due") || "");
        let hit = !q || String($r.data("search") || "").includes(q);
        hit =
          hit &&
          (!project || String($r.data("project")) === project) &&
          (!state || String($r.data("state")) === state) &&
          (!priority || String($r.data("priority")) === priority);
        if (quick === "overdue")
          hit = hit && String($r.data("overdue")) === "1";
        if (quick === "attention")
          hit = hit && String($r.data("attention")) === "1";
        if (quick === "today") {
          const d = due ? new Date(`${due}T00:00:00`) : null;
          hit = hit && d && d >= today && d < tomorrow;
        }
        if (quick === "next7") {
          const d = due ? new Date(`${due}T00:00:00`) : null;
          hit = hit && d && d >= tomorrow && d < next7;
        }
        $r.toggleClass("d-none", !hit);
        if (hit) count++;
      });
      $("#pf-task-visible-count").text(count);
    };
    $(
      "#pf-task-center-search,#pf-task-center-project,#pf-task-center-state,#pf-task-center-priority",
    ).on("input change", filter);
    $("[data-pf-task-quick]").on("click", function () {
      quick = String($(this).data("pf-task-quick") || "all");
      $("[data-pf-task-quick]").removeClass("is-selected");
      $(this).addClass("is-selected");
      filter();
    });
    filter();
  }

  function initConfigTabs() {
    const $tabs = $("[data-pf-config-tab]");
    if (!$tabs.length) return;
    const show = (name) => {
      if (!$tabs.filter(`[data-pf-config-tab="${name}"]`).length)
        name = "general";
      $tabs
        .removeClass("is-active")
        .attr("aria-selected", "false")
        .filter(`[data-pf-config-tab="${name}"]`)
        .addClass("is-active")
        .attr("aria-selected", "true");
      $("[data-pf-config-pane]").each(function () {
        const panes = String($(this).data("pf-config-pane")).split(/\s+/);
        $(this).toggleClass("d-none", !panes.includes(name));
      });
      try {
        localStorage.setItem("projectflow.config.tab", name);
      } catch {}
    };
    $tabs.on("click", function () {
      const n = String($(this).data("pf-config-tab"));
      show(n);
      history.replaceState(null, "", `#${n}`);
    });
    let initial = location.hash.slice(1);
    if (!initial) {
      try {
        initial = localStorage.getItem("projectflow.config.tab") || "";
      } catch {}
    }
    show(initial || "general");
  }

  function initAiConfig() {
    const $b = $("#pf-ai-test");
    if (!$b.length) return;
    $b.on("click", function () {
      const $f = $b.closest("form"),
        key = String($f.find('[name="ai_gemini_key"]').val() || ""),
        model = String($f.find('[name="ai_gemini_model"]').val() || ""),
        $r = $("#pf-ai-test-result");
      busy($b, true);
      $r.removeClass("pf-text-danger").text("Testando…");
      api(String($b.data("endpoint")), {
        action: "ai_test",
        key,
        model,
        _glpi_csrf_token: String($b.data("csrf") || ""),
      })
        .done((r) => {
          if (!r?.ok) {
            $r.addClass("pf-text-danger").text(r?.message || "Falha no teste.");
            return;
          }
          const $dl = $("#pf-ai-models").empty();
          (r.models || []).forEach((m) =>
            $("<option>").attr("value", m).appendTo($dl),
          );
          $r.toggleClass("pf-text-danger", !r.model_ok).text(
            r.message +
              (r.models?.length
                ? ` Modelos: ${r.models.slice(0, 8).join(", ")}${r.models.length > 8 ? "…" : ""}`
                : ""),
          );
        })
        .fail((x) =>
          $r
            .addClass("pf-text-danger")
            .text(x.responseJSON?.message || "Falha no teste."),
        )
        .always(() => busy($b, false));
    });
  }

  function initConfigCatalog() {
    const $app = $("#projectflow-config-catalog");
    if (!$app.length) return;
    const endpoint = String($app.data("endpoint")),
      csrf = csrfFor($app);
    const titles = {
      state: ["Novo estado", "Editar estado"],
      project_type: ["Novo tipo", "Editar tipo"],
      task_type: ["Novo tipo", "Editar tipo"],
      rule: ["Nova regra", "Editar regra"],
    };
    const resetForm = ($f, kind) => {
      $f[0].reset();
      $f.find('[name="id"]').val("0");
      $f.find("[data-pf-form-title]").text(titles[kind][0]);
      $f.find("[data-pf-catalog-cancel]").addClass("d-none");
      $f.removeClass("is-editing");
      $f.find('[name="is_paused"]').prop("disabled", false);
      $f.find('[name="percent"]').prop("readonly", false);
      setMessage($f, "");
    };
    const fillForm = ($f, kind, item) => {
      resetForm($f, kind);
      Object.entries(item || {}).forEach(([k, v]) => {
        const $i = $f.find(`[name="${k}"]`);
        if (!$i.length) return;
        if ($i.is(":checkbox")) $i.prop("checked", !!v);
        else $i.val(v === null || v === undefined ? "" : String(v));
      });
      $f.find('[name="is_finished"]').trigger("change");
      $f.find("[data-pf-form-title]").text(titles[kind][1]);
      $f.find("[data-pf-catalog-cancel]").removeClass("d-none");
      $f.addClass("is-editing");
      $f[0].scrollIntoView({ behavior: "smooth", block: "center" });
      $f.find('[name="name"]').trigger("focus");
    };
    const readItem = ($b) => {
      try {
        return JSON.parse(String($b.attr("data-item") || "{}"));
      } catch {
        return {};
      }
    };
    const post = (data, $f) =>
      api(endpoint, { _glpi_csrf_token: csrf, ...data })
        .done((r) => {
          if (!r?.ok) {
            $f
              ? setMessage($f, r?.message || "Falha ao salvar.", true)
              : toast(r?.message || "Falha.", "error");
            return;
          }
          window.location.reload();
        })
        .fail((x) => {
          const m = x.responseJSON?.message || "Falha ao salvar.";
          $f ? setMessage($f, m, true) : toast(m, "error");
        });
    $app.on("click", "[data-pf-catalog-edit]", function () {
      const kind = String($(this).data("pf-catalog-edit"));
      fillForm(
        $app.find(`.pf-catalog-form[data-kind="${kind}"]`),
        kind,
        readItem($(this)),
      );
    });
    $app.on("click", "[data-pf-rule-edit]", function () {
      fillForm($("#pf-rule-form"), "rule", readItem($(this)));
    });
    $app.on("click", "[data-pf-catalog-cancel]", function () {
      const $f = $(this).closest("form");
      resetForm($f, $f.is("#pf-rule-form") ? "rule" : String($f.data("kind")));
    });
    $app.on(
      "change",
      '.pf-catalog-form[data-kind="state"] [name="is_finished"]',
      function () {
        const $f = $(this).closest("form"),
          $p = $f.find('[name="percent"]');
        if (this.checked) {
          $p.val("100");
          $f.find('[name="is_paused"]').prop("checked", false);
        }
        $p.prop("readonly", this.checked);
        $f.find('[name="is_paused"]').prop("disabled", this.checked);
      },
    );
    $app.on(
      "change",
      '.pf-catalog-form[data-kind="state"] [name="is_paused"]',
      function () {
        if (this.checked)
          $(this)
            .closest("form")
            .find('[name="is_finished"]')
            .prop("checked", false)
            .trigger("change");
      },
    );
    $app.on("submit", ".pf-catalog-form[data-kind]", function (e) {
      e.preventDefault();
      const $f = $(this);
      post(
        {
          action: "catalog_save",
          kind: String($f.data("kind")),
          ...formDataObject(
            $f,
            $f.data("kind") === "state" ? ["is_finished", "is_paused"] : [],
          ),
        },
        $f,
      );
    });
    $("#pf-rule-form").on("submit", function (e) {
      e.preventDefault();
      const $f = $(this);
      post(
        {
          action: "rule_save",
          ...formDataObject($f, [
            "notify_requester",
            "notify_manager",
            "notify_team",
            "is_active",
          ]),
        },
        $f,
      );
    });
    $app.on("click", "[data-pf-catalog-delete]", function () {
      const $b = $(this);
      if (!confirm(`Excluir "${$b.data("name")}"?`)) return;
      post({
        action: "catalog_delete",
        kind: String($b.data("pf-catalog-delete")),
        id: Number($b.data("id")),
      });
    });
    $app.on("click", "[data-pf-rule-delete]", function () {
      const $b = $(this);
      if (!confirm(`Excluir a regra "${$b.data("name")}"?`)) return;
      post({ action: "rule_delete", id: Number($b.data("id")) });
    });
  }

  function initTaskStatesPicker() {
    // Per-task allowed states: chips keep a hidden "1,4,7" value ('' = every state) and, outside the
    // template task rows, restrict the state <select> of the same form to the allowed states.
    const sync = ($p) => {
      if ($p.hasClass("pf-template-task-states")) return;
      const $sel = $p.closest("form").find('select[name="projectstates_id"]');
      if (!$sel.length) return;
      const ids = String($p.find(".pf-task-states-value").val() || "")
        .split(",")
        .filter(Boolean);
      $sel.find("option").each(function () {
        const ok = !ids.length || ids.includes(String($(this).val()));
        $(this).prop("hidden", !ok).prop("disabled", !ok);
      });
      if ($sel.find("option:selected").prop("disabled")) {
        $sel.val(
          String($sel.find("option:not(:disabled)").first().val() || ""),
        );
      }
    };
    $(document).on("change", ".pf-task-state-check", function () {
      const $p = $(this).closest(".pf-task-states-picker");
      const $all = $p.find(".pf-task-state-check");
      if (!$all.filter(":checked").length) {
        $(this).prop("checked", true);
        toast("Deixe pelo menos um estado marcado.", "error");
        return;
      }
      const ids = $all
        .filter(":checked")
        .map(function () {
          return String($(this).val());
        })
        .get();
      $p.find(".pf-task-states-value").val(
        ids.length === $all.length ? "" : ids.join(","),
      );
      sync($p);
    });
    $(".pf-task-states-picker").each(function () {
      sync($(this));
    });
    $(document).on("reset", "form", function () {
      const $f = $(this);
      setTimeout(() => {
        $f.find(".pf-task-states-picker").each(function () {
          $(this).find(".pf-task-state-check").prop("checked", true);
          $(this).find(".pf-task-states-value").val("");
          sync($(this));
        });
      }, 0);
    });
  }
  function initFormTabs() {
    // Multi-step forms (create project / template): tabs like the settings screen + Voltar/Próximo.
    $("form")
      .has(".pf-form-tabs")
      .each(function () {
        const $f = $(this),
          form = this,
          $tabs = $f.find("[data-pf-form-tab]"),
          keys = $tabs
            .map(function () {
              return String($(this).data("pf-form-tab"));
            })
            .get();
        const show = (key) => {
          if (!keys.includes(key)) key = keys[0];
          const i = keys.indexOf(key);
          $tabs.each(function () {
            const on = String($(this).data("pf-form-tab")) === key;
            $(this)
              .toggleClass("is-active", on)
              .attr("aria-selected", on ? "true" : "false");
            $(this).toggleClass(
              "is-done",
              keys.indexOf(String($(this).data("pf-form-tab"))) < i,
            );
          });
          $f.find("[data-pf-form-pane]").each(function () {
            $(this).toggleClass(
              "d-none",
              String($(this).data("pf-form-pane")) !== key,
            );
          });
          $f.find(".pf-form-prev").toggleClass("d-none", i <= 0);
          $f.find(".pf-form-next").toggleClass("d-none", i >= keys.length - 1);
          $f.data("pfFormTab", key);
          const $body = $f.find(".modal-body");
          if ($body.length) $body.scrollTop(0);
        };
        const step = (d) => {
          const i = keys.indexOf(String($f.data("pfFormTab") || keys[0]));
          show(keys[Math.max(0, Math.min(keys.length - 1, i + d))]);
        };
        $tabs.on("click", function () {
          show(String($(this).data("pf-form-tab")));
        });
        $f.find(".pf-form-next").on("click", function () {
          step(1);
        });
        $f.find(".pf-form-prev").on("click", function () {
          step(-1);
        });
        form.addEventListener(
          "invalid",
          function (e) {
            const $pane = $(e.target).closest("[data-pf-form-pane]");
            if ($pane.length && $pane.hasClass("d-none")) {
              show(String($pane.data("pf-form-pane")));
              setTimeout(() => {
                try {
                  e.target.focus();
                } catch (_) {}
              }, 0);
            }
          },
          true,
        );
        $f.closest(".modal").on("show.bs.modal", function () {
          show(keys[0]);
        });
        show(keys[0]);
      });
  }
  $(function () {
    initConfigTabs();
    initAiConfig();
    initConfigCatalog();
    initDashboard();
    initTemplates();
    initWorkspace();
    initTask();
    initTaskCenter();
    initTaskStatesPicker();
    initFormTabs();
    initTaskPopupLinks();
  });
})();

/* Horizontal tab strips (project tabs, task popup tabs, template steps): keep the active tab visible. */
(function () {
  const sel = "[data-pf-tab],[data-pf-task-tab],[data-pf-form-tab]";
  const reveal = (el) => {
    const strip = el && el.parentElement;
    if (!strip) return;
    let s = strip;
    while (s && s !== document.body) {
      const ox = getComputedStyle(s).overflowX;
      if (ox === "auto" || ox === "scroll") break;
      s = s.parentElement;
    }
    if (!s || s === document.body || s.scrollWidth <= s.clientWidth) return;
    const r = el.getBoundingClientRect(),
      sr = s.getBoundingClientRect();
    if (r.left < sr.left) s.scrollLeft -= sr.left - r.left + 12;
    else if (r.right > sr.right) s.scrollLeft += r.right - sr.right + 12;
  };
  document.addEventListener(
    "click",
    (e) => {
      const el = e.target.closest && e.target.closest(sel);
      if (el) setTimeout(() => reveal(el), 0);
    },
    true,
  );
  const init = () =>
    document
      .querySelectorAll(
        "[data-pf-tab].is-active,[data-pf-task-tab].is-active,[data-pf-form-tab].is-active",
      )
      .forEach(reveal);
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", () => setTimeout(init, 50));
  else setTimeout(init, 50);
})();

/* Cronograma: Planejado / Real / Comparar (remembered per browser). */
(function () {
  const KEY = "pf-gx-view";
  const apply = (box, view) => {
    if (!["plan", "real", "compare"].includes(view)) view = "plan";
    box.classList.remove("is-view-plan", "is-view-real", "is-view-compare");
    box.classList.add("is-view-" + view);
    box
      .querySelectorAll("[data-pf-gx-view]")
      .forEach((b) =>
        b.classList.toggle("is-active", b.dataset.pfGxView === view),
      );
  };
  const init = () => {
    let saved = "plan";
    try {
      saved = localStorage.getItem(KEY) || "plan";
    } catch (e) {}
    document.querySelectorAll("[data-pf-gx]").forEach((box) => {
      apply(box, saved);
      box.addEventListener("click", (ev) => {
        const btn = ev.target.closest("[data-pf-gx-view]");
        if (!btn) return;
        ev.preventDefault();
        apply(box, btn.dataset.pfGxView);
        try {
          localStorage.setItem(KEY, btn.dataset.pfGxView);
        } catch (e) {}
      });
    });
  };
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", init);
  else init();
})();

/* Task list: arrow to show / hide the subtasks of a task (state remembered per project). */
(function () {
  const init = () => {
    document.querySelectorAll("[data-pf-tree]").forEach((table) => {
      const key = table.dataset.pfTreeKey || "pf-tree";
      const rows = Array.from(table.querySelectorAll("tr[data-pf-tree-id]"));
      const children = {};
      rows.forEach((r) => {
        (children[r.dataset.pfTreeParent] =
          children[r.dataset.pfTreeParent] || []).push(r);
      });
      let collapsed = new Set();
      try {
        collapsed = new Set(
          JSON.parse(localStorage.getItem(key) || "[]").map(String),
        );
      } catch (e) {}
      const save = () => {
        try {
          localStorage.setItem(key, JSON.stringify(Array.from(collapsed)));
        } catch (e) {}
      };
      const render = () => {
        const walk = (parentId, hidden) =>
          (children[parentId] || []).forEach((r) => {
            r.classList.toggle("pf-tree-hidden", hidden);
            const id = r.dataset.pfTreeId,
              closed = collapsed.has(id);
            const btn = r.querySelector("[data-pf-tree-toggle]");
            if (btn) {
              btn.setAttribute("aria-expanded", closed ? "false" : "true");
              btn.title = closed
                ? "Mostrar subtarefas (" + (children[id] || []).length + ")"
                : "Ocultar subtarefas";
              r.classList.toggle("is-collapsed", closed);
            }
            walk(id, hidden || closed);
          });
        // roots: parent 0 or a parent not listed (filtered out)
        const ids = new Set(rows.map((r) => r.dataset.pfTreeId));
        Object.keys(children)
          .filter((p) => p === "0" || !ids.has(p))
          .forEach((p) => walk(p, false));
      };
      table.addEventListener("click", (ev) => {
        const t = ev.target.closest("[data-pf-tree-toggle]");
        const all = ev.target.closest("[data-pf-tree-all]");
        if (t) {
          ev.preventDefault();
          const id = t.closest("tr").dataset.pfTreeId;
          collapsed.has(id) ? collapsed.delete(id) : collapsed.add(id);
        } else if (all) {
          ev.preventDefault();
          collapsed =
            all.dataset.pfTreeAll === "collapse"
              ? new Set(
                  rows
                    .filter((r) => children[r.dataset.pfTreeId])
                    .map((r) => r.dataset.pfTreeId),
                )
              : new Set();
        } else return;
        save();
        render();
      });
      render();
    });
  };
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", init);
  else init();
})();

/* Carga de trabalho (global e aba do projeto): horas planejadas x capacidade, por semana. */
(function () {
  const $ = window.jQuery;
  if (!$) return;
  const KEY = "pf-wl-prefs";
  const esc = (s) =>
    String(s ?? "").replace(
      /[&<>"']/g,
      (c) =>
        ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c],
    );
  const hours = (min) => {
    const h = (Number(min) || 0) / 60;
    if (h === 0) return "0h";
    if (h >= 10) return `${Math.round(h)}h`;
    return `${(Math.round(h * 10) / 10).toString().replace(".", ",")}h`;
  };
  const shortDate = (iso) => (iso ? iso.slice(8, 10) + "/" + iso.slice(5, 7) : "");
  const fullDate = (iso) =>
    iso ? `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : "";
  const addDays = (iso, n) => {
    const d = new Date(iso + "T12:00:00");
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
  };
  const level = (pct) =>
    pct === null || pct === undefined
      ? "na"
      : pct > 100
        ? "over"
        : pct >= 85
          ? "full"
          : pct >= 40
            ? "ok"
            : pct > 0
              ? "low"
              : "free";
  const loadPrefs = () => {
    try {
      return JSON.parse(localStorage.getItem(KEY) || "{}") || {};
    } catch {
      return {};
    }
  };
  const savePrefs = (p) => {
    try {
      localStorage.setItem(KEY, JSON.stringify(p));
    } catch {}
  };

  function mount(el) {
    if (el.dataset.pfWlReady) return;
    el.dataset.pfWlReady = "1";
    const focus = Number(el.dataset.focusProject || 0);
    const parseJson = (s) => {
      try {
        return JSON.parse(s || "[]");
      } catch {
        return [];
      }
    };
    const projects = parseJson(el.dataset.projects);
    const groups = parseJson(el.dataset.groups);
    const prefs = loadPrefs();
    const state = {
      start: "",
      weeks: [4, 8, 12].includes(prefs.weeks) ? prefs.weeks : 8,
      mode: prefs.mode === "plan" ? "plan" : "remaining",
      project_id: 0,
      group_id: 0,
    };
    let data = null;
    const open = new Set();

    el.innerHTML = `
      <div class="pf-wl-toolbar">
        <div class="pf-wl-nav" role="group" aria-label="Período">
          <button type="button" class="btn btn-icon btn-ghost-secondary" data-wl-shift="-1" title="Semanas anteriores"><i class="ti ti-chevron-left"></i></button>
          <button type="button" class="btn btn-ghost-secondary pf-wl-today" data-wl-today title="Voltar para a semana atual">Hoje</button>
          <button type="button" class="btn btn-icon btn-ghost-secondary" data-wl-shift="1" title="Próximas semanas"><i class="ti ti-chevron-right"></i></button>
          <strong class="pf-wl-range" data-wl-range></strong>
        </div>
        <div class="pf-wl-controls">
          <div class="pf-rp-seg" role="group" aria-label="O que contar">
            <button type="button" data-wl-mode="remaining" title="Só o que falta fazer, de hoje até o prazo. Tarefas atrasadas caem em hoje."><i class="ti ti-hourglass-high"></i>Restante</button>
            <button type="button" data-wl-mode="plan" title="O planejado original, no período planejado, incluindo tarefas concluídas"><i class="ti ti-calendar-stats"></i>Planejado</button>
          </div>
          <select class="form-select form-select-sm" data-wl-weeks aria-label="Semanas">
            <option value="4">4 semanas</option><option value="8">8 semanas</option><option value="12">12 semanas</option>
          </select>
          ${
            focus
              ? ""
              : `<select class="form-select form-select-sm" data-wl-project aria-label="Projeto"><option value="0">Todos os projetos</option>${projects
                  .map((p) => `<option value="${p.id}">${esc(p.name)}</option>`)
                  .join("")}</select>
          <select class="form-select form-select-sm" data-wl-group aria-label="Grupo"><option value="0">Todos os grupos</option>${groups
            .map((g) => `<option value="${g.id}">${esc(g.name)}</option>`)
            .join("")}</select>`
          }
        </div>
      </div>
      <div class="pf-wl-kpis" data-wl-kpis></div>
      <div class="pf-wl-body" data-wl-body><div class="pf-wl-loading"><i class="ti ti-loader-2 pf-spin"></i> Calculando a carga…</div></div>
      <div class="pf-wl-foot" data-wl-foot></div>`;

    const $el = $(el);
    $el.find(`[data-wl-weeks]`).val(String(state.weeks));
    const syncControls = () => {
      $el
        .find("[data-wl-mode]")
        .removeClass("is-active")
        .filter(`[data-wl-mode="${state.mode}"]`)
        .addClass("is-active");
    };
    syncControls();

    const load = () => {
      $el.addClass("is-loading");
      $.ajax({
        url: el.dataset.endpoint,
        method: "POST",
        dataType: "json",
        data: {
          action: "build",
          start: state.start,
          weeks: state.weeks,
          mode: state.mode,
          project_id: state.project_id,
          group_id: state.group_id,
          focus_project_id: focus,
          _glpi_csrf_token: el.dataset.csrf,
        },
      })
        .done((res) => {
          if (!res || !res.ok) throw new Error(res?.message);
          data = res.workload;
          state.start = data.start;
          render();
        })
        .fail((xhr) => {
          $el
            .find("[data-wl-body]")
            .html(
              `<div class="pf-wl-error"><i class="ti ti-alert-triangle"></i> ${esc(xhr?.responseJSON?.message || "Não foi possível calcular a carga de trabalho.")}</div>`,
            );
        })
        .always(() => $el.removeClass("is-loading"));
    };

    const meter = (cell, cap) => {
      const base = cap > 0 ? cap : Math.max(cell.total, 1);
      const w = (v) => Math.min(100, (v / base) * 100).toFixed(1);
      const parts = focus
        ? `<span class="is-main" style="width:${w(cell.main)}%"></span><span class="is-other" style="width:${w(cell.other + cell.hidden)}%"></span>`
        : `<span class="is-main" style="width:${w(cell.main + cell.other)}%"></span><span class="is-hidden" style="width:${w(cell.hidden)}%"></span>`;
      return `<div class="pf-wl-meter">${parts}</div>`;
    };

    const cellHtml = (row, cell, i) => {
      const cap = row.capacity[i];
      const lv =
        row.type !== "user"
          ? cell.total
            ? "na"
            : "free"
          : cell.no_capacity
            ? "over"
            : level(cell.pct);
      const week = data.weeks[i];
      const tip = [
        `${week.label} a ${shortDate(week.end)}`,
        `${hours(cell.total)} de trabalho` + (cap ? ` · capacidade ${hours(cap)}` : ""),
        cell.pct !== null && cell.pct !== undefined ? `${cell.pct}% ocupado` : "",
        cell.no_capacity ? "Sem dias úteis disponíveis nesta semana" : "",
        focus && cell.other + cell.hidden ? `${hours(cell.other + cell.hidden)} em outros projetos` : "",
        !focus && cell.hidden ? `${hours(cell.hidden)} em projetos que você não vê` : "",
        cell.overdue ? `${hours(cell.overdue)} de tarefas atrasadas` : "",
      ]
        .filter(Boolean)
        .join("\n");
      const over = cap && cell.total > cap ? `<em>+${hours(cell.total - cap)}</em>` : "";
      return `<td class="pf-wl-cell is-${lv}${week.is_current ? " is-current" : ""}${week.is_past ? " is-past" : ""}${cell.overdue ? " has-overdue" : ""}" title="${esc(tip)}">
        <div class="pf-wl-num"><strong>${cell.total || cap ? hours(cell.total) : "–"}</strong>${cap ? `<small>/ ${hours(cap)}</small>` : ""}${over}</div>
        ${cell.total || cap ? meter(cell, cap) : ""}
      </td>`;
    };

    const rowHead = (row) => {
      const icon =
        row.type === "group" ? "ti-users-group" : row.type === "none" ? "ti-user-question" : "";
      const avatar = icon
        ? `<span class="pf-wl-avatar is-${row.type}"><i class="ti ${icon}"></i></span>`
        : `<span class="pf-wl-avatar">${esc(row.initials)}</span>`;
      let sub;
      if (row.type === "user") {
        sub =
          row.pct === null
            ? "Sem capacidade no período"
            : `${row.pct}% no período${row.overloaded_weeks ? ` · <b>${row.overloaded_weeks} sem. acima</b>` : ""}`;
        if (row.overloaded_weeks) sub = `<b>${row.overloaded_weeks} sem. acima</b> · ${row.pct ?? 0}% no período`;
      } else if (row.type === "group") sub = "Grupo · sem pessoa definida";
      else sub = "Tarefas sem executor";
      if (row.no_estimate)
        sub += ` · <span class="pf-wl-noest" title="Tarefas no período sem duração planejada: não entram na conta">${row.no_estimate} sem estimativa</span>`;
      const can = row.tasks.length || row.load.some((c) => c.hidden || c.other);
      return `<th class="pf-wl-who" scope="row">
        <button type="button" class="pf-wl-toggle" data-wl-row="${esc(row.key)}" ${can ? "" : "disabled"} aria-expanded="${open.has(row.key)}">
          <i class="ti ti-chevron-right pf-wl-caret"></i>${avatar}
          <span class="pf-wl-name"><strong>${esc(row.name)}</strong><small>${sub}</small></span>
        </button>
      </th>`;
    };

    const detailRows = (row) => {
      if (!open.has(row.key)) return "";
      const n = data.weeks.length;
      let html = row.tasks
        .map((t) => {
          const cells = Array.from({ length: n }, (_, i) => {
            const m = t.weeks[i] || 0;
            return `<td class="pf-wl-dcell${data.weeks[i].is_current ? " is-current" : ""}">${m ? hours(m) : ""}</td>`;
          }).join("");
          const flags = [
            t.overdue ? '<span class="pf-wl-flag is-late">atrasada</span>' : "",
            t.other_project ? `<span class="pf-wl-flag">outro projeto</span>` : "",
            t.assignees > 1 ? `<span class="pf-wl-flag" title="As horas são divididas entre ${t.assignees} responsáveis">÷${t.assignees}</span>` : "",
          ].join("");
          return `<tr class="pf-wl-detail${t.other_project ? " is-other" : ""}"><th scope="row"><a href="${esc(t.url)}"><strong>${esc(t.name)}</strong></a>
            <small>${focus && !t.other_project ? "" : esc(t.project_name) + " · "}${fullDate(t.from)}${t.to !== t.from ? " – " + fullDate(t.to) : ""} · ${t.percent}%</small>${flags}</th>${cells}</tr>`;
        })
        .join("");
      const hidden = row.load.map((c) => c.hidden);
      if (hidden.some(Boolean)) {
        html += `<tr class="pf-wl-detail is-hidden"><th scope="row"><strong><i class="ti ti-lock"></i> Projetos que você não vê</strong><small>Só as horas aparecem</small></th>${hidden
          .map((m, i) => `<td class="pf-wl-dcell${data.weeks[i].is_current ? " is-current" : ""}">${m ? hours(m) : ""}</td>`)
          .join("")}</tr>`;
      }
      return html;
    };

    const render = () => {
      const w = data.weeks;
      $el
        .find("[data-wl-range]")
        .text(`${fullDate(data.start)} – ${fullDate(data.end)}`);
      const s = data.summary;
      $el.find("[data-wl-kpis]").html(`
        <div class="pf-wl-kpi"><span><i class="ti ti-users"></i></span><div><small>Pessoas</small><strong>${s.people}</strong></div></div>
        <div class="pf-wl-kpi ${s.overloaded ? "is-danger" : ""}"><span><i class="ti ti-flame"></i></span><div><small>Sobrecarregadas</small><strong>${s.overloaded}</strong></div></div>
        <div class="pf-wl-kpi is-${level(s.pct)}"><span><i class="ti ti-gauge"></i></span><div><small>Ocupação ${focus ? "da equipe" : "geral"}</small><strong>${s.pct === null ? "–" : s.pct + "%"}</strong><em>${hours(s.load)} de ${hours(s.capacity)}</em></div></div>
        <div class="pf-wl-kpi ${s.no_estimate ? "is-warning" : ""}" title="Tarefas no período sem duração planejada. Elas não entram na carga: preencha a duração para a conta ficar certa."><span><i class="ti ti-clock-question"></i></span><div><small>Sem estimativa</small><strong>${s.no_estimate}</strong></div></div>`);

      if (!data.rows.length) {
        $el.find("[data-wl-body]").html(`<div class="pf-empty-state"><i class="ti ti-mood-empty"></i><strong>Ninguém com tarefas neste período</strong><p>${focus ? "Adicione pessoas à equipe ou atribua executores às tarefas do projeto." : "Ajuste os filtros ou o período."} Só tarefas com início ou fim planejado entram na carga.</p></div>`);
        $el.find("[data-wl-foot]").empty();
        return;
      }
      const head = w
        .map(
          (wk) =>
            `<th scope="col" class="pf-wl-wk${wk.is_current ? " is-current" : ""}${wk.is_past ? " is-past" : ""}"><span>${wk.is_current ? "Esta semana" : "Semana"}</span><strong>${wk.label}</strong></th>`,
        )
        .join("");
      const body = data.rows
        .map(
          (row) =>
            `<tr class="pf-wl-row is-${row.type}${open.has(row.key) ? " is-open" : ""}">${rowHead(row)}${row.load
              .map((c, i) => cellHtml(row, c, i))
              .join("")}</tr>${detailRows(row)}`,
        )
        .join("");
      $el.find("[data-wl-body]").html(`<div class="pf-wl-scroll"><table class="pf-wl-table" style="--pf-wl-weeks:${w.length}">
        <thead><tr><th scope="col" class="pf-wl-who-h">${focus ? "Equipe" : "Pessoa"}</th>${head}</tr></thead><tbody>${body}</tbody></table></div>`);
      const daily = hours(data.daily_minutes);
      $el.find("[data-wl-foot]").html(`
        <div class="pf-wl-legend">
          <span><i class="is-low"></i>Folga (&lt; 40%)</span><span><i class="is-ok"></i>Ocupado</span><span><i class="is-full"></i>No limite (≥ 85%)</span><span><i class="is-over"></i>Acima da capacidade</span>
          ${focus ? '<span><i class="is-other"></i>Outros projetos</span>' : '<span><i class="is-hidden"></i>Projetos que você não vê</span>'}
        </div>
        <p>${state.mode === "remaining" ? "Restante: o que falta de cada tarefa (duração × % pendente), de hoje até o prazo; tarefas atrasadas contam no próximo dia útil e a capacidade começa hoje." : "Planejado: a duração planejada de cada tarefa espalhada pelos dias úteis do período planejado."}
        Horas divididas entre os responsáveis. Capacidade pelo calendário do GLPI, até ${daily} por dia.</p>`);
    };

    $el.on("click", "[data-wl-shift]", function () {
      const dir = Number(this.dataset.wlShift);
      if (!data) return;
      state.start = addDays(data.start, dir * state.weeks * 7);
      load();
    });
    $el.on("click", "[data-wl-today]", () => {
      state.start = "";
      load();
    });
    $el.on("click", "[data-wl-mode]", function () {
      state.mode = this.dataset.wlMode;
      savePrefs({ weeks: state.weeks, mode: state.mode });
      syncControls();
      load();
    });
    $el.on("change", "[data-wl-weeks]", function () {
      state.weeks = Number(this.value) || 8;
      savePrefs({ weeks: state.weeks, mode: state.mode });
      load();
    });
    $el.on("change", "[data-wl-project]", function () {
      state.project_id = Number(this.value) || 0;
      load();
    });
    $el.on("change", "[data-wl-group]", function () {
      state.group_id = Number(this.value) || 0;
      load();
    });
    $el.on("click", "[data-wl-row]", function () {
      const k = this.dataset.wlRow;
      open.has(k) ? open.delete(k) : open.add(k);
      render();
    });
    load();
  }

  const init = () => {
    document.querySelectorAll("[data-pf-workload]").forEach((el) => {
      if (el.dataset.lazy !== "1" || el.closest(".pf-tab-pane.is-active")) mount(el);
    });
  };
  document.addEventListener("click", (e) => {
    const t = e.target.closest && e.target.closest('[data-pf-tab="workload"]');
    if (t) setTimeout(() => document.querySelectorAll("[data-pf-workload]").forEach(mount), 0);
  });
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", () => setTimeout(init, 0));
  else setTimeout(init, 0);
})();


/* Custos das tarefas: aprovar / recusar / remover (tela da tarefa e aba Aprovações do projeto). */
(function () {
  const $ = window.jQuery;
  if (!$) return;
  const ctx = (el) => {
    const host = el.closest("[data-task-endpoint]");
    return host ? { url: host.dataset.taskEndpoint, csrf: host.dataset.csrf } : null;
  };
  const note = (text, error) => {
    let $stack = $(".pf-toast-stack");
    if (!$stack.length) $stack = $('<div class="pf-toast-stack" aria-live="polite"></div>').appendTo("body");
    const $t = $(`<div class="pf-toast is-${error ? "error" : "success"}"><i class="ti ${error ? "ti-alert-triangle" : "ti-circle-check"}"></i><div></div></div>`);
    $t.find("div").text(text);
    $stack.append($t);
    setTimeout(() => $t.fadeOut(200, () => $t.remove()), 4200);
  };
  const reloadWith = (msg) => {
    try {
      sessionStorage.setItem("pf-toast", msg);
    } catch {}
    window.location.reload();
  };
  try {
    const pending = sessionStorage.getItem("pf-toast");
    if (pending) {
      sessionStorage.removeItem("pf-toast");
      $(() => note(pending));
    }
  } catch {}
  const send = (el, data, $btn) => {
    const c = ctx(el);
    if (!c) return;
    $btn?.prop("disabled", true).addClass("pf-loading");
    $.ajax({ url: c.url, method: "POST", dataType: "json", data: { ...data, _glpi_csrf_token: c.csrf } })
      .done((r) => (r?.ok ? reloadWith(r.message || "Feito.") : note(r?.message || "Não foi possível concluir.", true)))
      .fail((x) => note(x.responseJSON?.message || "Não foi possível concluir.", true))
      .always(() => $btn?.prop("disabled", false).removeClass("pf-loading"));
  };
  $(document).on("click", "[data-pf-cost-decide]", function () {
    const box = this.closest("[data-cost-id]");
    if (!box) return;
    const decision = this.dataset.pfCostDecide;
    const comment = String($(box).find("input, textarea").val() || "").trim();
    if (decision === "reject" && !comment && !confirm("Recusar este custo sem comentário? O autor não vai saber o motivo.")) return;
    send(box, { action: "cost_decide", cost_id: box.dataset.costId, decision, comment }, $(this));
  });
  $(document).on("click", ".pf-cost-delete", function () {
    const approved = /financeiro/.test(this.title || "");
    if (!confirm(approved ? "Remover este custo aprovado? Ele também sai do financeiro do projeto." : "Remover este custo?")) return;
    send(this, { action: "cost_delete", cost_id: this.dataset.id }, $(this));
  });
  // Aprovações: filtro Aguardando / Todos
  $(document).on("click", "[data-pf-ap-filter]", function () {
    const v = this.dataset.pfApFilter;
    const root = this.closest(".pf-ap");
    if (!root) return;
    root.querySelectorAll("[data-pf-ap-filter]").forEach((b) => b.classList.toggle("is-active", b === this));
    let shown = 0;
    root.querySelectorAll("[data-pf-ap-status]").forEach((row) => {
      const ok = v === "all" || row.dataset.pfApStatus === v;
      row.classList.toggle("d-none", !ok);
      if (ok) shown++;
    });
    root.querySelector("[data-pf-ap-empty]")?.classList.toggle("d-none", shown > 0);
  });
})();
