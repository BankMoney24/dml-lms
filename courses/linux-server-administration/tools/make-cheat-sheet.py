# Regenerates files/linux-server-cheat-sheet.pdf (needs: pip install reportlab).
# Usage: python3 tools/make-cheat-sheet.py files/linux-server-cheat-sheet.pdf
import sys
from reportlab.lib.pagesizes import A4
from reportlab.lib import colors
from reportlab.lib.units import mm
from reportlab.lib.styles import ParagraphStyle
from reportlab.platypus import BaseDocTemplate, Frame, PageTemplate, Paragraph, Table, TableStyle, Spacer, KeepTogether

TEAL = colors.HexColor("#0B7A6B"); INK = colors.HexColor("#14213D"); SOFT = colors.HexColor("#E3F2EF"); LAMP = colors.HexColor("#F2B705")
h = ParagraphStyle("h", fontName="Helvetica-Bold", fontSize=10, textColor=TEAL, spaceBefore=6, spaceAfter=2)
cmd = ParagraphStyle("c", fontName="Courier-Bold", fontSize=7.6, leading=9.2, textColor=INK)
txt = ParagraphStyle("t", fontName="Helvetica", fontSize=7.6, leading=9.2, textColor=INK)

S = [
 ("Find your way", [("cat /etc/os-release","Which distro and version"),("pwd / ls -la / cd -","Where am I, what is here, go back"),("find /etc -name '*.conf'","Find files by name"),("grep -rn 'Listen' /etc/httpd/","Search inside files"),("less file / tail -f log","Read a file / follow a log"),("man cmd / cmd --help","Help")]),
 ("Files and permissions", [("cp -a src dst / mv / rm -r","Copy keeping permissions, move, delete"),("chmod 640 file / chmod g+w","Numeric / symbolic permissions"),("chown user:group file","Change owner and group"),("chmod 2770 /srv/project","Shared folder: setgid keeps the group"),("ls -ld dir / ls -Z","Directory itself / SELinux labels"),("umask","Default permissions for new files")]),
 ("Users and sudo", [("useradd -m -s /bin/bash tobi","New user with home folder"),("usermod -aG wheel tobi","Add to group (-a: keep others!)"),("id tobi / groups","Who is in which group"),("sudo -i / visudo","Root shell / edit sudoers safely")]),
 ("Packages", [("dnf upgrade | apt update && apt upgrade","Install updates"),("dnf install X | apt install X","Install a package"),("dnf provides /usr/bin/dig","Which package has this file"),("dnf history / dnf history undo N","Review / undo a transaction"),("dnf needs-restarting -r","Reboot needed? (AlmaLinux)")]),
 ("Services and logs", [("systemctl status|restart|reload X","Control a service"),("systemctl enable --now X","Start now and at boot"),("systemctl --failed","Anything broken?"),("journalctl -u X -n 50 -f","Last 50 lines of X, then follow"),("journalctl -p err -b","Errors since boot"),("systemctl daemon-reload","After editing unit files")]),
 ("Processes and resources", [("ps aux | grep [n]ginx","Find a process"),("top / htop","Live view (M: memory, P: CPU)"),("kill PID / kill -9 PID","Ask to stop / force (last resort)"),("uptime / nproc / free -h","Load vs cores / memory"),("df -h / df -i / du -sh *","Disk space / inodes / folder sizes")]),
 ("Network", [("ip a / ip r","Addresses / routes"),("ss -tulpn","What listens on which port"),("curl -I https://site","HTTP headers only"),("dig +short name A","DNS lookup"),("nc -zv host 22","Is a TCP port open?")]),
 ("Firewall", [("firewall-cmd --list-all","AlmaLinux: what is allowed"),("firewall-cmd --permanent --add-service=https","Allow a service..."),("firewall-cmd --reload","...then apply"),("ufw allow OpenSSH; ufw enable","Ubuntu: SSH FIRST, then enable"),("ufw status verbose","Ubuntu: what is allowed")]),
 ("SSH", [("ssh-keygen -t ed25519","New key (set a passphrase)"),("ssh-copy-id user@host","Install your public key"),("sshd -t && systemctl reload sshd","Test, then reload"),("sshd -T | grep -i password","Settings actually in force")]),
 ("Web and HTTPS", [("nginx -t && systemctl reload nginx","Test, then reload Nginx"),("apachectl configtest","Test Apache config"),("certbot --nginx -d site","Get a certificate"),("certbot renew --dry-run","Prove renewal works")]),
 ("Database", [("sudo mysql","Admin login via socket"),("mysqldump --single-transaction db | gzip > db.sql.gz","Consistent backup"),("gunzip < db.sql.gz | mysql newdb","Restore into a test DB")]),
 ("SELinux (AlmaLinux)", [("getenforce","Enforcing? Keep it on"),("restorecon -Rv /var/www","Fix labels after mv"),("setsebool -P httpd_can_network_connect_db on","Allow web app -> remote DB"),("ausearch -m AVC -ts recent | audit2why","Why was it blocked?")]),
]

def block(title, rows):
    data = [[Paragraph(c.replace("&","&amp;").replace("<","&lt;").replace(">","&gt;"), cmd), Paragraph(d, txt)] for c,d in rows]
    t = Table(data, colWidths=[52*mm, 37*mm])
    t.setStyle(TableStyle([("VALIGN",(0,0),(-1,-1),"TOP"),("ROWBACKGROUNDS",(0,0),(-1,-1),[colors.white, SOFT]),
        ("LEFTPADDING",(0,0),(-1,-1),3),("RIGHTPADDING",(0,0),(-1,-1),3),("TOPPADDING",(0,0),(-1,-1),2.2),("BOTTOMPADDING",(0,0),(-1,-1),2.2)]))
    return KeepTogether([Paragraph(title, h), t])

def page(c, doc):
    c.saveState()
    c.setFillColor(INK); c.rect(0, A4[1]-20*mm, A4[0], 20*mm, stroke=0, fill=1)
    c.setFillColor(LAMP); c.circle(14*mm, A4[1]-10*mm, 2.2*mm, stroke=0, fill=1)
    c.setFillColor(colors.white); c.setFont("Helvetica-Bold", 14); c.drawString(20*mm, A4[1]-11.5*mm, "Linux Server Administration: command cheat sheet")
    c.setFont("Helvetica", 7.5); c.drawString(20*mm, A4[1]-16*mm, "AlmaLinux / RHEL family and Ubuntu / Debian. Use sudo where you are not root. Test configuration before every reload.")
    c.setFillColor(colors.HexColor("#4A5873")); c.setFont("Helvetica", 7)
    c.drawString(10*mm, 7*mm, "DML School of Technology - Learn it. Practise it. Prove it.")
    c.drawRightString(A4[0]-10*mm, 7*mm, "Never chmod 777. Never disable SELinux. Always test a restore.")
    c.restoreState()

doc = BaseDocTemplate(sys.argv[1], pagesize=A4, leftMargin=8*mm, rightMargin=8*mm, topMargin=24*mm, bottomMargin=12*mm,
                      title="Linux Server Administration cheat sheet", author="DML School of Technology")
w = (A4[0]-16*mm-6*mm)/2
frames = [Frame(8*mm, 12*mm, w, A4[1]-36*mm, id="l", leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0),
          Frame(8*mm+w+6*mm, 12*mm, w, A4[1]-36*mm, id="r", leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)]
doc.addPageTemplates([PageTemplate(id="p", frames=frames, onPage=page)])
order = ["Find your way","Files and permissions","Users and sudo","Packages","Services and logs","Processes and resources","SSH","Network","Firewall","Web and HTTPS","Database","SELinux (AlmaLinux)"]
by = dict(S)
doc.build([block(t, by[t]) for t in order])
