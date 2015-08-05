Vagrant.configure("2") do |config|
  config.vm.box = "hashicorp/precise64"

  config.vm.network :private_network, ip: "192.168.2.110"
    config.ssh.forward_agent = true
  config.vm.network "forwarded_port", guest: 80, host: 8090

  config.vm.provider :virtualbox do |v|
    v.customize ["modifyvm", :id, "--natdnshostresolver1", "on"]
  end

  config.vm.synced_folder "./", "/var/www/html/pramnosframework2", id: "vagrant-root"
  config.vm.provision :shell, :path => "_build/vagrant/bootstrap.sh"
end