#!/usr/bin/env perl
# SPDX-License-Identifier: GPL-3.0-or-later
use strict;
use warnings;
use IO::Pty;
use POSIX qw(setsid WNOHANG);

my ($child_pid, $child_reaped) = (0, 0);

sub reap_child_group {
    return if !$child_pid || $child_reaped;
    kill 'TERM', -$child_pid;
    for (1 .. 20) {
        my $result = waitpid($child_pid, WNOHANG);
        if ($result == $child_pid || $result == -1) {
            $child_reaped = 1;
            return;
        }
        select(undef, undef, undef, 0.05);
    }
    kill 'KILL', -$child_pid;
    waitpid($child_pid, 0);
    $child_reaped = 1;
}

$SIG{HUP} = sub { reap_child_group(); exit 129; };
$SIG{INT} = sub { reap_child_group(); exit 130; };
$SIG{TERM} = sub { reap_child_group(); exit 143; };
END { reap_child_group(); }

my ($mode, $input, $wait_for) = ('input', '', 'Username [admin]');
my @steps;
while (@ARGV && $ARGV[0] ne '--') {
    my $argument = shift @ARGV;
    $mode = $1 if $argument =~ /^--mode=(input|eof|interrupt)$/;
    $input = $1 if $argument =~ /^--input=(.*)$/s;
    $wait_for = $1 if $argument =~ /^--wait-for=(.*)$/s;
    if ($argument =~ /^--step=(.*)$/s) {
        my ($pattern, $action, $payload) = split(/\|/, $1, 3);
        push @steps, [$pattern, $action, $payload // ''];
    }
}
shift @ARGV if @ARGV && $ARGV[0] eq '--';
die "PTY command is required\n" unless @ARGV;

my $pty = IO::Pty->new or die "Unable to create PTY\n";
my $pid = fork;
die "Unable to fork PTY child\n" unless defined $pid;
if ($pid == 0) {
    setsid() or die "Unable to create PTY session\n";
    my $slave = $pty->slave;
    $pty->make_slave_controlling_terminal();
    open(STDIN, '<&', $slave) or die "Unable to redirect PTY stdin\n";
    open(STDOUT, '>&', $slave) or die "Unable to redirect PTY stdout\n";
    open(STDERR, '>&', $slave) or die "Unable to redirect PTY stderr\n";
    close($slave);
    close($pty);
    exec @ARGV;
    die "Unable to execute PTY command\n";
}

$child_pid = $pid;
my $output = '';
@steps = ([$wait_for, $mode, unpack('H*', $input)]) unless @steps;
my $step = 0;
my $scan_from = 0;
my $deadline = time + 20;
while (1) {
    my $readable = '';
    vec($readable, fileno($pty), 1) = 1;
    select($readable, undef, undef, 0.1);
    if (vec($readable, fileno($pty), 1)) {
        my $chunk = '';
        my $read = sysread($pty, $chunk, 4096);
        $output .= $chunk if defined $read && $read > 0;
    }
    if ($step < @steps && index($output, $steps[$step][0], $scan_from) >= 0) {
        my ($pattern, $action, $payload) = @{$steps[$step]};
        if ($action eq 'input') {
            my $decoded = pack('H*', $payload);
            my $argument_value = $decoded;
            $argument_value =~ s/\r?\n\z//;
            if (-d '/proc') {
                for my $process (glob('/proc/[0-9]*')) {
                    my ($process_id) = $process =~ m{/proc/(\d+)\z};
                    next unless defined $process_id;
                    open(my $stat_file, '<', "$process/stat") or next;
                    my $stat = <$stat_file> // '';
                    close($stat_file);
                    my ($process_group) = $stat =~ /^\d+ \(.*\) \S+ \d+ (\d+) /;
                    next unless $process_id == $$
                        || (defined $process_group && $process_group == $pid);
                    my $arguments = "$process/cmdline";
                    open(my $command, '<', $arguments) or next;
                    local $/;
                    my $contents = <$command> // '';
                    close($command);
                    die "PTY input appeared in process arguments\n"
                        if $argument_value ne '' && index($contents, $argument_value) >= 0;
                }
            }
            syswrite($pty, $decoded);
        }
        elsif ($action eq 'eof') { syswrite($pty, chr(4)); }
        elsif ($action eq 'interrupt') { kill 'INT', -$pid; }
        elsif ($action eq 'ready') {
            open(my $ready, '>', $payload) or die "Unable to create PTY ready file\n";
            print {$ready} "ready\n";
            close($ready) or die "Unable to close PTY ready file\n";
        }
        else { die "Unknown PTY action: $action\n"; }
        $step++;
        $scan_from = length($output);
    }
    my $result = waitpid($pid, WNOHANG);
    if ($result == $pid) {
        my $status = $?;
        $child_reaped = 1;
        print $output;
        die "PTY child exited before all input steps ran\n" if $step != @steps;
        exit($status & 127 ? 128 + ($status & 127) : $status >> 8);
    }
    if (time > $deadline) {
        reap_child_group();
        print $output;
        die "PTY command timed out\n";
    }
}
