$(document).ready(function() {
  const ROLE = window.APP_ROLE || "guest";

  function computeStatus(absences, participation) {
    if (absences >= 5) {
      return {
        message: "Excluded - too many absences. You need to participate more",
        rowClass: "red"
      };
    }

    const attendancePart = absences <= 2 ? "Good attendance" : "Average attendance";
    let participationPart = "needs to participate more";
    if (participation >= 5) {
      participationPart = "excellent participation";
    } else if (participation >= 3) {
      participationPart = "good participation";
    }

    return {
      message: `${attendancePart} - ${participationPart}`,
      rowClass: absences <= 2 ? "green" : "yellow"
    };
  }

  function escapeHtml(str) {
    return (str || "").toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function updateTable() {
    $("#attendance tbody tr").each(function() {
      let derivedAbs = 0;
      let derivedPar = 0;
      $(this).find("td:nth-child(n+3):nth-child(-n+8)").each(function() {
        const cellText = $(this).text().trim().toUpperCase();
        if (cellText === "P") {
          derivedPar++;
        } else {
          derivedAbs++;
        }
      });

      const manualAbsRaw = $(this).data("manualAbsOverride");
      const manualAbs = manualAbsRaw !== undefined ? parseInt(manualAbsRaw, 10) : NaN;
      const dataAbsRaw = parseInt($(this).attr("data-total-abs"), 10);
      const dataParRaw = parseInt($(this).attr("data-total-par"), 10);

      let absToUse = Number.isInteger(dataAbsRaw) ? dataAbsRaw : derivedAbs;
      let parToUse = Number.isInteger(dataParRaw) ? dataParRaw : derivedPar;

      if (Number.isInteger(manualAbs) && manualAbs >= 0) {
        absToUse = manualAbs;
      }

      const status = computeStatus(absToUse, parToUse);
      $(this).find("td:eq(8)").text(`${absToUse} Abs`);
      $(this).find("td:eq(10)").text(`${parToUse} Par`);
      $(this).find("td:eq(11)").text(status.message);

      $(this).removeClass("green yellow red");
      if (status.rowClass) {
        $(this).addClass(status.rowClass);
      }
    });
  }

  function setAbsenceError(message) {
    $("#editAbsError").text(message || "");
  }

  // Elements and helpers powering the custom absence modal.
  const $absenceModal = $("#absenceModal");
  const $absenceModalForm = $("#absenceModalForm");
  const $absenceModalInput = $("#absenceModalInput");
  const $absenceModalStudent = $("#absenceModalStudent");
  const $absenceModalCancel = $("#absenceModalCancel");
  let $activeAbsRow = null;

  function openAbsenceModal($row) {
    $activeAbsRow = $row;
    const studentName = `${$row.find("td:eq(0)").text()} ${$row.find("td:eq(1)").text()}`.trim() || "this student";
    const currentAbsAttr = parseInt($row.attr("data-total-abs"), 10);
    const currentAbs = Number.isInteger(currentAbsAttr) ? currentAbsAttr : (parseInt($row.find("td:eq(8)").text(), 10) || 0);
    $absenceModalStudent.text(studentName);
    $absenceModalInput.val(currentAbs);
    setAbsenceError("");
    $absenceModal.attr("aria-hidden", "false").addClass("visible");
    setTimeout(() => {
      $absenceModalInput.trigger("focus").select();
    }, 0);
  }

  function closeAbsenceModal() {
    $absenceModal.removeClass("visible").attr("aria-hidden", "true");
    if ($absenceModalForm.length) {
      $absenceModalForm[0].reset();
    }
    $activeAbsRow = null;
  }

  // Admin student edit modal.
  const $studentModal = $("#studentModal");
  const $studentModalForm = $("#studentModalForm");
  const $studentModalError = $("#studentModalError");
  let $activeStudentRow = null;

  function openStudentModal($row) {
    $activeStudentRow = $row;
    $("#modalStudentId").val($row.data("studentIdCode") || "");
    $("#modalLastName").val($row.find("td:eq(0)").text());
    $("#modalFirstName").val($row.find("td:eq(1)").text());
    $("#modalEmail").val($row.data("studentEmail") || "");
    $("#modalModule").val($row.data("module") || "");
    $("#modalSection").val($row.data("section") || "");
    const groupVal = $row.data("group") || "";
    $("#modalGroup").val(groupVal);
    $studentModalError.text("");
    $studentModal.attr("aria-hidden", "false").addClass("visible");
    setTimeout(() => $("#modalStudentId").trigger("focus"), 0);
  }

  function closeStudentModal() {
    $studentModal.removeClass("visible").attr("aria-hidden", "true");
    $studentModalError.text("");
    $activeStudentRow = null;
  }

  // Popup for viewing a student's current absence count.
  const $infoPopup = $("#infoPopup");
  const $infoPopupName = $("#infoPopupName");
  const $infoPopupDetails = $("#infoPopupDetails");
  const $infoPopupOk = $("#infoPopupOk");

  function showAbsenceInfo(name, count) {
    const displayName = name.trim() || "Unknown student";
    const absValue = Number.isFinite(count) && count >= 0 ? count : 0;
    $infoPopupName.text(displayName);
    $infoPopupDetails.text(`- ${absValue} Abs`);
    $infoPopup.attr("aria-hidden", "false").addClass("visible");
    setTimeout(() => $infoPopupOk.trigger("focus"), 0);
  }

  function closeInfoPopup() {
    $infoPopup.removeClass("visible").attr("aria-hidden", "true");
  }

  function renderStudents(students) {
    const rows = (students || []).map((stu) => {
      const marks = (stu.marks || []).slice(0, 6);
      while (marks.length < 6) marks.push("");
      const absences = parseInt(stu.absences, 10) || 0;
      const participations = parseInt(stu.participations, 10) || 0;
      const status = computeStatus(absences, participations);
      const markCells = marks.map((m) => `<td>${escapeHtml(m)}</td>`).join("");
      const deleteBtn = ROLE === "admin"
        ? `<button type="button" class="delete-student-btn" aria-label="Delete ${escapeHtml(stu.last_name || "")} ${escapeHtml(stu.first_name || "")}">Delete</button>`
        : "";
      return `<tr
        data-student-id="${stu.id}"
        data-total-abs="${absences}"
        data-total-par="${participations}"
        data-student-id-code="${escapeHtml(stu.student_id || "")}"
        data-student-email="${escapeHtml(stu.email || "")}"
        data-group="${escapeHtml(stu.group_id || "")}"
        data-module="${escapeHtml(stu.module || "")}"
        data-section="${escapeHtml(stu.section || "")}"
      >
        <td>${escapeHtml(stu.last_name || "")}</td>
        <td>${escapeHtml(stu.first_name || "")}</td>
        ${markCells}
        <td>${absences} Abs</td>
        <td class="edit-cell">
          <button type="button" class="edit-absence-btn" aria-label="Edit absences for ${escapeHtml(stu.last_name || "")} ${escapeHtml(stu.first_name || "")}">Edit</button>
          ${deleteBtn}
        </td>
        <td>${participations} Par</td>
        <td>${escapeHtml(status.message)}</td>
      </tr>`;
    }).join("");
    $("#attendance tbody").html(rows);
    updateTable();
  }

  function loadStudents() {
    if (!$("#attendance").length) return;
    $.getJSON("list_students.php", function(resp) {
      if (resp && resp.success) {
        renderStudents(resp.students || []);
      }
    });
  }

  function renderSessions(sessions) {
    const rows = (sessions || []).map((s) => `
      <tr data-session-id="${s.id}">
        <td>${s.id}</td>
        <td>${escapeHtml(s.course_id || "")}</td>
        <td>${escapeHtml(s.group_id || "")}</td>
        <td>${escapeHtml(s.date || "")}</td>
        <td>${escapeHtml(s.status || "")}</td>
        <td><a class="btn-ghost" href="take_attendance.php?session_id=${s.id}">Take / Edit</a></td>
      </tr>
    `).join("");
    $("#sessionTableBody").html(rows);
  }

  function loadSessions() {
    if (!$("#sessionTableBody").length) return;
    $.getJSON("list_sessions.php", function(resp) {
      if (resp && resp.success) {
        renderSessions(resp.sessions || []);
      }
    });
  }

  updateTable();

  $("#studentForm").submit(function(e) {
    e.preventDefault();
    $(".error").text("");
    if (ROLE !== "admin") {
      $("#idError").text("Only administrators can add students.");
      return;
    }
    let valid = true;
    const id = $("#studentId").val().trim();
    const last = $("#lastName").val().trim();
    const first = $("#firstName").val().trim();
    const email = $("#email").val().trim();
    const group = $("#group").val().trim();

    if (!/^[0-9]+$/.test(id)) { $("#idError").text("Only numbers"); valid = false; }
    if (!/^[A-Za-z]+$/.test(last)) { $("#lastError").text("Letters only"); valid = false; }
    if (!/^[A-Za-z]+$/.test(first)) { $("#firstError").text("Letters only"); valid = false; }
    if (email && !/^[^@]+@[^@]+\.[^@]+$/.test(email)) { $("#emailError").text("Invalid email"); valid = false; }
    if (group && !/^[A-Za-z0-9_-]+$/.test(group)) { $("#groupError").text("Letters/numbers only"); valid = false; }

    if (!valid) return;

    $.post("add_student.php", {
      student_id: id,
      last_name: last,
      first_name: first,
      email: email,
      group_id: group
    }, function(resp) {
      if (resp && resp.success) {
        loadStudents();
        $("#studentForm")[0].reset();
        $("#studentId").focus();
      } else {
        $("#idError").text(resp.message || "Could not add student.");
      }
    }, "json");
  });

  $("#showReport").click(function() {
    const rows = $("#attendance tbody tr");
    const total = rows.length;
    let markedPresent = 0;
    let participated = 0;

    rows.each(function() {
      const absValue = parseInt($(this).attr("data-total-abs"), 10);
      const parValue = parseInt($(this).attr("data-total-par"), 10);
      const absToUse = Number.isInteger(absValue) ? absValue : (parseInt($(this).find("td:eq(8)").text(), 10) || 0);
      const parToUse = Number.isInteger(parValue) ? parValue : (parseInt($(this).find("td:eq(10)").text(), 10) || 0);

      if (absToUse < 6) {
        markedPresent++;
      }
      participated += parToUse;
    });

    $("#report").html(`
      <h2>Weekly snapshot</h2>
      <div class="stat-grid">
        <div class="stat-card">
          <p class="label">Total Students</p>
          <p class="value">${total}</p>
        </div>
        <div class="stat-card">
          <p class="label">Marked Present</p>
          <p class="value">${markedPresent}</p>
        </div>
        <div class="stat-card">
          <p class="label">Total Participations</p>
          <p class="value">${participated}</p>
        </div>
      </div>
      <p class="hint-text">Numbers refresh every time you click "Show Report".</p>
    `);
  });

  $("#attendance tbody")
    .on("mouseenter", "tr", function() {
      $(this).addClass("highlight");
    })
    .on("mouseleave", "tr", function() {
      $(this).removeClass("highlight");
    })
    .on("click", "tr", function() {
      const name = `${$(this).find("td:eq(0)").text()} ${$(this).find("td:eq(1)").text()}`;
      const absValue = parseInt($(this).attr("data-total-abs"), 10) || parseInt($(this).find("td:eq(8)").text(), 10) || 0;
      showAbsenceInfo(name, absValue);
    });

  // Wire modal actions into the existing absence update flow.
  $("#attendance tbody").on("click", ".edit-absence-btn", function(e) {
    e.stopPropagation();
    const $row = $(this).closest("tr");
    if (ROLE === "admin") {
      openStudentModal($row);
    } else {
      openAbsenceModal($row);
    }
  });

  $("#attendance tbody").on("click", ".delete-student-btn", function(e) {
    e.stopPropagation();
    if (ROLE !== "admin") return;
    const $row = $(this).closest("tr");
    const id = parseInt($row.data("studentId"), 10);
    if (!id || !confirm("Delete this student?")) return;
    $.post("delete_student.php", { id }, function(resp) {
      if (resp && resp.success) {
        loadStudents();
      } else {
        setAbsenceError(resp.message || "Could not delete student.");
      }
    }, "json");
  });

  $absenceModalForm.on("submit", function(e) {
    e.preventDefault();
    if (!$activeAbsRow) {
      setAbsenceError("");
      return;
    }

    const rawValue = $absenceModalInput.val();
    const trimmedValue = (rawValue ?? "").toString().trim();
    if (trimmedValue === "") {
      setAbsenceError("Absences cannot be empty.");
      return;
    }

    const nextValue = Number(trimmedValue);
    if (!Number.isInteger(nextValue) || nextValue < 0) {
      setAbsenceError("Absences must be an integer greater than or equal to 0.");
      return;
    }

    setAbsenceError("");
    $activeAbsRow.data("manualAbsOverride", nextValue);
    $activeAbsRow.attr("data-total-abs", nextValue);
    updateTable();
    closeAbsenceModal();
  });

  $absenceModalCancel.on("click", function() {
    setAbsenceError("");
    closeAbsenceModal();
  });

  $absenceModal.on("click", function(e) {
    if (e.target === this) {
      setAbsenceError("");
      closeAbsenceModal();
    }
  });

  $studentModalForm.on("submit", function(e) {
    e.preventDefault();
    if (ROLE !== "admin" || !$activeStudentRow) {
      closeStudentModal();
      return;
    }
    const payload = {
      id: $activeStudentRow.data("studentId"),
      student_id: $("#modalStudentId").val().trim(),
      last_name: $("#modalLastName").val().trim(),
      first_name: $("#modalFirstName").val().trim(),
      email: $("#modalEmail").val().trim(),
      module: $("#modalModule").val().trim(),
      section: $("#modalSection").val().trim(),
      group_id: $("#modalGroup").val().trim()
    };
    $.post("update_student.php", payload, function(resp) {
      if (resp && resp.success) {
        loadStudents();
        closeStudentModal();
      } else {
        $studentModalError.text(resp.message || "Could not update student.");
      }
    }, "json");
  });

  // Sync group slider with text input on the student modal.
  $("#modalGroupSlider").on("input change", function() {
    $("#modalGroup").val($(this).val());
  });
  $("#modalGroup").on("input", function() {
    const num = parseInt($(this).val(), 10);
    if (Number.isFinite(num)) {
      $("#modalGroupSlider").val(num);
    }
  });

  $("#studentModalCancel").on("click", function() {
    closeStudentModal();
  });

  $studentModal.on("click", function(e) {
    if (e.target === this) {
      closeStudentModal();
    }
  });

  $infoPopupOk.on("click", function() {
    closeInfoPopup();
  });

  $infoPopup.on("click", function(e) {
    if (e.target === this) {
      closeInfoPopup();
    }
  });

  $(document).on("keydown", function(e) {
    if (e.key === "Escape") {
      if ($absenceModal.hasClass("visible")) {
        setAbsenceError("");
        closeAbsenceModal();
      }
      if ($studentModal.hasClass("visible")) {
        closeStudentModal();
      }
      if ($infoPopup.hasClass("visible")) {
        closeInfoPopup();
      }
    }
  });

  $("#highlightExcellent").click(function() {
    $("#attendance tbody tr").each(function() {
      const absValue = parseInt($(this).attr("data-total-abs"), 10) || 0;
      if (absValue < 3) {
        $(this)
          .stop(true, true)
          .fadeOut(120)
          .fadeIn(120)
          .css("background-color", "#d8f7ef");
      }
    });
  });

  $("#resetColors").click(function() {
    $("#attendance tbody tr").css("background-color", "");
    updateTable();
  });

  $("#searchName").on("keyup", function() {
    const value = $(this).val().toLowerCase();
    $("#attendance tbody tr").filter(function() {
      $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
    });
  });

  $("#sortAbs").click(function() {
    const rows = $("#attendance tbody tr").get();
    rows.sort(function(a, b) {
      const absA = parseInt($(a).attr("data-total-abs"), 10) || 0;
      const absB = parseInt($(b).attr("data-total-abs"), 10) || 0;
      return absA - absB;
    });
    $("#attendance tbody").append(rows);
    $("#sortMessage").text("Currently sorted by absences (ascending).");
  });

  $("#sortPar").click(function() {
    const rows = $("#attendance tbody tr").get();
    rows.sort(function(a, b) {
      const parA = parseInt($(a).attr("data-total-par"), 10) || 0;
      const parB = parseInt($(b).attr("data-total-par"), 10) || 0;
      return parB - parA;
    });
    $("#attendance tbody").append(rows);
    $("#sortMessage").text("Currently sorted by participation (descending).");
  });

  $("#createSession").on("click", function() {
    if (ROLE !== "teacher" && ROLE !== "admin") return;
    const courseId = $("#courseId").val().trim();
    const groupId = $("#groupId").val().trim();
    const date = $("#sessionDate").val();
    $("#sessionMessage").text("");
    $.post("create_session.php", { course_id: courseId, group_id: groupId, date: date }, function(resp) {
      $("#sessionMessage").text(resp.message || (resp.success ? "Session created." : "Could not create session."));
      if (resp && resp.success) {
        loadSessions();
      }
    }, "json");
  });

  loadStudents();
  loadSessions();
});
