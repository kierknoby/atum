#!/usr/bin/env perl
# SPDX-License-Identifier: GPL-3.0-or-later
use strict;
use warnings;
use POSIX qw(WIFEXITED WEXITSTATUS WIFSIGNALED WTERMSIG);

@ARGV >= 6 or die "usage: $0 SIGNAL EXPECTED_STATUS READY_FILE -- COMMAND...\n";
my $signal = shift @ARGV;
my $expected = shift @ARGV;
my $ready = shift @ARGV;
shift @ARGV eq '--' or die "missing -- before command\n";

my $pid = fork();
defined $pid or die "fork failed: $!\n";
if ($pid == 0) {
    $SIG{HUP} = 'DEFAULT';
    $SIG{INT} = 'DEFAULT';
    $SIG{TERM} = 'DEFAULT';
    POSIX::setpgid(0, 0);
    exec @ARGV or die "exec failed: $!\n";
}

my $ready_seen = 0;
for (1 .. 100) {
    if (-s $ready) {
        $ready_seen = 1;
        last;
    }
    select undef, undef, undef, 0.05;
}
if (!$ready_seen) {
    kill 'TERM', $pid;
    waitpid $pid, 0;
    die "child did not become ready\n";
}

if ($signal ne 'WAIT') {
    kill $signal, -$pid or die "unable to send $signal: $!\n";
}
waitpid $pid, 0;
my $status = WIFEXITED($?) ? WEXITSTATUS($?)
    : WIFSIGNALED($?) ? 128 + WTERMSIG($?)
    : 255;
die "expected status $expected after $signal, got $status\n"
    if $status != $expected;

for (1 .. 20) {
    last if kill(0, -$pid) == 0;
    select undef, undef, undef, 0.05;
}
if (kill(0, -$pid) != 0) {
    kill 'KILL', -$pid;
    die "process group $pid remained after $signal completion\n";
}
