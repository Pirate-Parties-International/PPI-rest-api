(function($) {
    var app = angular.module('app', []);    
        app.controller('pictureController', ['$scope', function($scope) {
            $scope.greeting = 'Hola!';
            //temporary function that creates fake data in the same format as I expect to get the data though an API
            var getFakeData = function(){
                var data = {};           
                for (var i = 0; i < 1000; i++){
                    data[i] = { url: "http://loremflickr.com/200/200?random="+i};
                    if ((i%2)==0){
                        data[i]["socialPlatform"] = "FB"
                        data[i]["likes"] = 10000 + (i)*2
                    }
                    else {
                        data[i]["socialPlatform"] = "TW"
                        data[i]["likes"] = 10000 + (-i)*2
                    };
                };
                return data;
        	};
            //Function that runs after data is acquired
            $.when(getFakeData()).done(function(data){ 
                console.log(data)
        });
   }]);
})(jQuery); // end of jQuery name space