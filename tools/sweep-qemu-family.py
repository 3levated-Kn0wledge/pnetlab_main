import re, sys

EXEMPT = {
 "$flags .= ' ' . $this->getFlag();":
   ("        // sweep-exempt: the template's qemu_options string, meant to supply multiple\n"
    "        // arguments. Escaping it as one would break every template.\n"),
 "$flags .= ' ' . $qoptions;":
   ("        // sweep-exempt: same as getFlag() — a multi-argument options string.\n"),
}

def transform(path):
    src = open(path).read()
    lines = src.split('\n')
    out = []
    n = 0
    for ln in lines:
        stripped = ln.strip()
        if 'escapeshellarg' in ln or stripped.startswith('//'):
            out.append(ln); continue

        # first_nic branch is dead code (never assigned anywhere in the tree)
        if "' -device '.$this->first_nic." in ln:
            ind = ln[:len(ln)-len(ln.lstrip())]
            out.append(ind + "// sweep-exempt: $this->first_nic is never assigned in the tree; branch unreachable.")
            out.append(ln); n += 1; continue

        if stripped in EXEMPT:
            out.append(EXEMPT[stripped].rstrip('\n'))
            out.append(ln); n += 1; continue

        # -device / -netdev / -drive option values -> one escaped argument
        m = re.match(r"^(\s*)\$flags (\.?=) ' -(device|netdev|drive) (.*?)';(\s*(?://.*)?)$", ln)
        if m:
            ind, op, opt, body, tail = m.groups()
            out.append(f"{ind}$flags {op} ' -{opt} ' . escapeshellarg('{body}');{tail}"); n += 1; continue
        m = re.match(r"^(\s*)\$flags (\.?=) ' -(device|netdev|drive) (.*?)( \. \([^;]*\));(\s*(?://.*)?)$", ln)
        if m:
            ind, op, opt, body, texpr, tail = m.groups()
            # body ends with the literal's closing quote; drop it so we do not
            # emit '' when re-quoting.
            if body.endswith("'"):
                body = body[:-1]
            out.append(f"{ind}$flags {op} ' -{opt} ' . escapeshellarg('{body}'{texpr});{tail}"); n += 1; continue

        # simple scalar flags
        m = re.match(r"^(\s*)\$flags \.= ' -(smp|m|name|uuid) ' \. (\$this->\w+);(\s*(?://.*)?)$", ln)
        if m:
            ind, opt, var, tail = m.groups()
            out.append(f"{ind}$flags .= ' -{opt} ' . escapeshellarg({var});{tail}"); n += 1; continue

        # image paths
        m = re.match(r"^(\s*)\$flags \.= ' -(cdrom|kernel) (/opt/unetlab/addons/qemu/)' \. (\$this->image) \. '(/[\w.]+)';(\s*(?://.*)?)$", ln)
        if m:
            ind, opt, pre, var, post, tail = m.groups()
            out.append(f"{ind}$flags .= ' -{opt} ' . escapeshellarg('{pre}' . {var} . '{post}');{tail}"); n += 1; continue

        m = re.match(r"^(\s*)\$flags \.= ' -hd' \. (\$disk_id) \. ' ' \. (\$filename);(\s*(?://.*)?)$", ln)
        if m:
            ind, d, f, tail = m.groups()
            out.append(f"{ind}$flags .= ' -hd' . {d} . ' ' . escapeshellarg({f});{tail}"); n += 1; continue

        # iptables --dport
        m = re.match(r"^(\s*)\$cmd = 'iptables -t nat -([DI]) INPUT -p tcp --dport ' \. (\$this->get\w+\(\)) \. ' -j SNAT --to ([\d.]+)';(\s*(?://.*)?)$", ln)
        if m:
            ind, act, var, ip, tail = m.groups()
            out.append(f"{ind}$cmd = 'iptables -t nat -{act} INPUT -p tcp --dport ' . escapeshellarg({var}) . ' -j SNAT --to {ip}';{tail}"); n += 1; continue

        out.append(ln)
    open(path, 'w').write('\n'.join(out))
    return n

for p in sys.argv[1:]:
    print(f"{p}: {transform(p)} lines rewritten")
