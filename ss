[1mdiff --git a/index.html b/index.html[m
[1mindex c367c26..9694682 100644[m
[1m--- a/index.html[m
[1m+++ b/index.html[m
[36m@@ -3,6 +3,8 @@[m
 <head>[m
 <meta charset="UTF-8">[m
 <meta name="viewport" content="width=device-width, initial-scale=1.0">[m
[32m+[m[32m<link rel="stylesheet"[m
[32m+[m[32m      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">[m
 <title>User Management</title>[m
 <style>[m
     * { box-sizing: border-box; }[m
[36m@@ -190,7 +192,7 @@[m
 </div>[m
 [m
 <script>[m
[31m-const API_URL = "http://localhost/apiss/project 1/user.php";[m
[32m+[m[32mconst API_URL = "http://localhost/apiss/project1/user.php";[m
 [m
 let currentPage = 1;[m
 const limit = 5;[m
[36m@@ -318,15 +320,19 @@[m [mfunction displayUsers(users) {[m
             <td class="action-column">[m
                 <button type="button"[m
                     ${inactive ? "disabled" : ""}[m
[31m-                    onclick="updateUser(${Number(user.id)})">U</button>[m
[32m+[m[32m                    onclick="updateUser(${Number(user.id)})">[m[41m   [m
[32m+[m[32m                    <i class="fa-solid fa-pen"></i>[m
[32m+[m[32m                </button>[m
 [m
                 <button type="button"[m
                     ${inactive ? "disabled" : ""}[m
[31m-                    onclick="timelog(${Number(user.id)})">V</button>[m
[32m+[m[32m                    onclick="timelog(${Number(user.id)})">[m
[32m+[m[32m                    <i class="fa-solid fa-eye"></i>[m
[32m+[m[32m                </button>[m
 [m
                 <button type="button"[m
                     onclick="toggleStatus(${Number(user.id)})">[m
[31m-                    D[m
[32m+[m[32m                    <i class="fa-solid fa-trash"></i>[m
                 </button>[m
             </td>[m
         `;[m
[36m@@ -340,7 +346,7 @@[m [mfunction displayUsers(users) {[m
         historyRow.innerHTML = `[m
             <td colspan="10">[m
                 <div class="history-box" id="history-box-${user.id}">[m
[31m-                    <h3>History</h3>[m
[32m+[m[32m                    <h3 align='center'>History</h3>[m
                     <div class="table-wrap">[m
                         <table class="history-table">[m
                             <thead>[m
[36m@@ -348,6 +354,7 @@[m [mfunction displayUsers(users) {[m
                                     <th>S.No</th>[m
                                     <th>Action</th>[m
                                     <th>Details</th>[m
[32m+[m[32m                                    <th>Time</th>[m
                                 </tr>[m
                             </thead>[m
                             <tbody id="timelog-${user.id}"></tbody>[m
[36m@@ -412,6 +419,7 @@[m [masync function timelog(id) {[m
                     <td>${index + 1}</td>[m
                     <td>${escapeHtml(log.action)}</td>[m
                     <td>${details}</td>[m
[32m+[m[32m                    <td>${escapeHtml(log.times)}</td>[m
                 `;[m
 [m
                 box.appendChild(row);[m
